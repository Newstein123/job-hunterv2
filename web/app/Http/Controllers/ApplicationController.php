<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Jobs\GenerateApplicationContent;
use App\Models\Application;
use App\Models\Country;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Services\ApplicationStarter;
use App\Services\Mail\EmailQueue;
use App\Services\Mail\RecipientValidator;
use App\Services\Mail\ReplyText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = ApplicationStatus::tryFrom($request->string('status')->toString());
        $countryId = $request->integer('country') ?: null;
        $kind = in_array($kind = $request->string('kind')->toString(), [JobPosting::KIND_POSTING, JobPosting::KIND_SPECULATIVE], true) ? $kind : null;
        $search = trim($request->string('q')->toString());

        $applications = $user->applications()
            ->with(['job:id,position,company,country_id,kind', 'job.country:id,name', 'resume:id,label', 'outboundMessage',
                'emailMessages:id,application_id,direction,status,in_reply_to_id,is_auto_reply,from_email,received_at,sent_at,created_at'])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($countryId, fn ($q, $id) => $q->whereHas('job', fn ($j) => $j->where('country_id', $id)))
            ->when($kind, fn ($q, $k) => $q->whereHas('job', fn ($j) => $j->where('kind', $k)))
            ->when($search !== '', fn ($q) => $q->whereHas('job', fn ($j) => $j->where(fn ($j) => $j
                ->where('position', 'like', "%{$search}%")->orWhere('company', 'like', "%{$search}%"))))
            ->latest('updated_at')->paginate(20)->withQueryString()
            ->through(fn (Application $a) => [
                'id' => $a->id,
                'status' => $a->status->value,
                'generation_state' => $a->generation_state,
                'position' => $a->job->displayTitle(),
                'company' => $a->job->company,
                'country' => $a->job->country?->name,
                'kind' => $a->job->kind,
                'resume' => $a->resume?->label,
                'has_errors' => $a->validationErrors() !== [],
                'reply_state' => $a->replyState(),
                'scheduled_at' => $a->status === ApplicationStatus::Queued ? $a->outboundMessage?->scheduled_at?->toIso8601String() : null,
                'updated_at' => $a->updated_at->toIso8601String(),
            ]);

        return Inertia::render('applications/index', [
            'applications' => $applications,
            'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], ApplicationStatus::cases()),
            'countries' => Country::options(),
            'filters' => ['status' => $status?->value, 'country' => $countryId, 'kind' => $kind, 'q' => $search ?: null],
        ]);
    }

    /** Start an application for a job: creates it and queues AI generation. */
    public function store(Request $request, JobPosting $job, ApplicationStarter $starter): RedirectResponse
    {
        $this->ensureOwns($request, $job);
        $user = $request->user();

        if ($existing = $job->application) {
            $this->toast('This job already has an application.', 'info');

            return to_route('applications.show', $existing);
        }
        if (! $user->canAnalyzeJobs()) {
            throw ValidationException::withMessages([
                'application' => 'Add your career profile and at least one active resume first.',
            ]);
        }

        return to_route('applications.show', $starter->start($user, $job));
    }

    public function show(Request $request, Application $application): Response
    {
        $this->ensureOwns($request, $application);
        $application->load(['job.country:id,name', 'resume:id,label,original_filename', 'latestStrategy', 'statusHistory', 'outboundMessage.account:id,email']);
        $message = $application->outboundMessage;

        return Inertia::render('applications/show', [
            'application' => [
                ...$application->only([
                    'id', 'generation_state', 'generation_error', 'subject', 'body', 'cover_letter', 'resume_id', 'cc', 'bcc',
                ]),
                'status' => $application->status->value,
                'validation' => $application->validation ?? ['errors' => [], 'warnings' => [], 'attempts' => 0],
                'edited_at' => $application->edited_at?->toIso8601String(),
                'approved_at' => $application->approved_at?->toIso8601String(),
                'resume' => $application->resume,
                'job' => [
                    ...$application->job->only(['id', 'position', 'company', 'location', 'contact_email', 'url']),
                    'country' => $application->job->country?->name,
                ],
                'strategy' => $application->latestStrategy?->only([
                    'match', 'resume_selection', 'skill_strategy', 'application_strategy', 'meta',
                ]),
                'history' => $application->statusHistory->map->only(['id', 'from_status', 'to_status', 'note', 'created_at']),
                'email_account_id' => $application->email_account_id,
                'outbound' => $message ? [
                    ...$message->only(['id', 'status', 'to_email', 'cc', 'bcc', 'from_email', 'attempts', 'last_error']),
                    'scheduled_at' => $message->scheduled_at?->toIso8601String(),
                    'sent_at' => $message->sent_at?->toIso8601String(),
                ] : null,
                'replies' => $application->emailMessages()->where('direction', EmailMessage::INBOUND)->latest('received_at')
                    ->get(['id', 'from_email', 'received_at']),
                'conversation' => $this->conversation($application),
                'reply_state' => $application->replyState(),
            ],
            'accounts' => $request->user()->emailAccounts()->orderByDesc('is_default')->orderBy('email')
                ->get(['id', 'email', 'status', 'status_reason', 'is_default', 'daily_limit']),
            'resumes' => $request->user()->resumes()->where('is_active', true)->get(['id', 'label', 'original_filename']),
            'can' => [
                'edit' => $this->canEdit($application),
                'approve' => $application->status === ApplicationStatus::Generated && ! $application->isGenerating(),
                'regenerate' => $application->status->isEditable() && ! $application->isGenerating(),
                'reopen' => $application->status === ApplicationStatus::Approved,
                'withdraw' => $application->status->canTransitionTo(ApplicationStatus::Withdrawn),
                'queue' => $application->status === ApplicationStatus::Approved,
                'cancelSend' => $application->status === ApplicationStatus::Queued && $message?->status !== EmailMessage::SENDING,
                'retrySend' => $application->status === ApplicationStatus::Queued && $message?->status === EmailMessage::FAILED,
                'editRecipients' => $this->canEditRecipients($application),
                'resend' => $application->status === ApplicationStatus::Sent && (bool) $message?->bounced(),
            ],
            'trackingOptions' => array_map(
                fn ($s) => ['value' => $s->value, 'label' => $s->label()],
                $application->status->trackingOptions(),
            ),
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($this->canEdit($application), 409, 'This application can no longer be edited.');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'cover_letter' => ['nullable', 'string', 'max:20000'],
            'resume_id' => ['required', Rule::exists('resumes', 'id')
                ->where('user_id', $request->user()->id)->where('is_active', true)],
        ]);
        $application->update([...$data, 'edited_at' => now()]);
        $this->toast('Changes saved.');

        return back();
    }

    /**
     * Fix To / CC / BCC. Allowed whenever there's no email in flight: before approval, after approval,
     * while scheduled (the schedule is kept), after a failed send ("Save & retry") and after a bounce
     * ("Save & resend"). The approved subject and body stay locked.
     */
    public function updateRecipients(Request $request, Application $application, EmailQueue $queue, RecipientValidator $recipients): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        $application->load(['job', 'outboundMessage']);
        $message = $application->outboundMessage;
        abort_unless($this->canEditRecipients($application), 409, 'Recipients can\'t be changed right now.');

        $request->merge([
            'to' => strtolower(trim((string) $request->input('to'))),
            'cc' => JobPosting::parseEmails($request->input('cc')),
            'bcc' => JobPosting::parseEmails($request->input('bcc')),
        ]);
        $data = $request->validate([
            'to' => ['required', 'email:rfc,strict', 'max:255'],
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email:rfc,strict', 'max:255', 'distinct', Rule::notIn([$request->input('to')])],
            'bcc' => ['nullable', 'array', 'max:10'],
            'bcc.*' => ['email:rfc,strict', 'max:255', 'distinct'],
            'then' => ['nullable', Rule::in(['save', 'retry', 'resend'])],
            'email_account_id' => ['nullable', 'integer', Rule::exists('email_accounts', 'id')->where('user_id', $request->user()->id)],
        ]);

        // Same pre-send checks as sending, shown on the field that has the problem.
        $errors = [];
        foreach (['to' => [$data['to']], 'cc' => $data['cc'] ?? [], 'bcc' => $data['bcc'] ?? []] as $field => $emails) {
            if ($problems = $recipients->problemsForAll($emails, $application->user_id)) {
                $errors[$field] = implode('; ', $problems).'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $then = $data['then'] ?? 'save';
        DB::transaction(function () use ($application, $message, $data, $then, $queue, $request) {
            $application->job->update(['contact_email' => $data['to']]);
            $application->update(['cc' => $data['cc'], 'bcc' => $data['bcc']]);

            // A scheduled email keeps its time but goes to the corrected addresses.
            if ($message && in_array($message->status, [EmailMessage::QUEUED, EmailMessage::SCHEDULED, EmailMessage::RETRYING], true)) {
                $message->update(['to_email' => $data['to'], 'cc' => $data['cc'], 'bcc' => $data['bcc']]);
                $message->recordEvent('recipients_changed', ['to' => $data['to']]);
            }

            if ($then === 'save') {
                return;
            }
            $account = isset($data['email_account_id'])
                ? EmailAccount::query()->whereKey($data['email_account_id'])->first()
                : $message?->account;
            if ($account === null) {
                throw ValidationException::withMessages(['email_account_id' => 'Choose an account to send from.']);
            }
            $fresh = $application->refresh();
            if ($then === 'retry' && $message?->status === EmailMessage::FAILED) {
                $queue->retry($fresh, $account, $request->user());
            } elseif ($then === 'resend' && $message?->bounced()) {
                $queue->resend($fresh, $account, $request->user());
            }
        });

        $this->toast(match ($then) {
            'retry' => 'Saved and queued again.',
            'resend' => 'Saved. Sending again to the corrected address.',
            default => 'Recipients saved.',
        });

        return back();
    }

    public function regenerate(Request $request, Application $application): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status->isEditable() && ! $application->isGenerating(), 409);

        $application->update(['generation_state' => Application::GENERATION_PENDING, 'generation_error' => null]);
        GenerateApplicationContent::dispatch($application);
        $this->toast('Regenerating application…', 'info');

        return back();
    }

    /** Approve; with an email_account_id this is "Approve & send" (queued, sent by the scheduler). */
    public function approve(Request $request, Application $application, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status === ApplicationStatus::Generated && ! $application->isGenerating(), 409);

        // Unresolved validation problems need an explicit acknowledgement unless the user edited the draft.
        if ($application->validationErrors() && ! $application->edited_at && ! $request->boolean('acknowledge')) {
            throw ValidationException::withMessages([
                'acknowledge' => 'This draft has unresolved validation errors. Edit it, or confirm you reviewed them.',
            ]);
        }

        // "Approve only" approves without queueing (it can be scheduled later from the Jobs list).
        $account = $request->boolean('approve_only') ? null : $this->account($request);
        DB::transaction(function () use ($application, $request, $queue, $account) {
            $application->transitionTo(ApplicationStatus::Approved, $request->user(), 'Approved after review', [
                'approved_at' => now(),
            ]);
            if ($account) {
                $queue->queue($application, $account, $request->user());
            }
        });
        $this->toast($account ? 'Approved and queued for sending.' : 'Application approved.');

        return back();
    }

    /** Move an approved application back to review so it can be edited. */
    public function reopen(Request $request, Application $application): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status === ApplicationStatus::Approved, 409);

        $application->transitionTo(ApplicationStatus::Generated, $request->user(), 'Reopened for editing', [
            'approved_at' => null,
        ]);

        return back();
    }

    public function queue(Request $request, Application $application, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status === ApplicationStatus::Approved, 409);
        $account = $this->account($request, required: true);

        $queue->queue($application, $account, $request->user());
        $this->toast('Queued for sending.');

        return back();
    }

    public function cancelSend(Request $request, Application $application, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status === ApplicationStatus::Queued, 409);

        $queue->cancel($application, $request->user());
        $this->toast('Sending cancelled. The application is approved and not sent.');

        return back();
    }

    public function retrySend(Request $request, Application $application, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->outboundMessage?->status === EmailMessage::FAILED, 409);
        $account = $this->account($request, required: true);

        $queue->retry($application, $account, $request->user());
        $this->toast('Queued again.');

        return back();
    }

    public function withdraw(Request $request, Application $application, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        abort_unless($application->status->canTransitionTo(ApplicationStatus::Withdrawn), 409);
        if ($application->status === ApplicationStatus::Queued) {
            $queue->cancel($application, $request->user(), 'Sending cancelled');
            $application->refresh();
        }

        $application->transitionTo(ApplicationStatus::Withdrawn, $request->user(), $request->string('note')->toString() ?: 'Cancelled by user');
        $this->toast('Application cancelled.');

        return back();
    }

    /** Record an outcome (sent manually, replied, interview, rejected, offer). */
    public function track(Request $request, Application $application): RedirectResponse
    {
        $this->ensureOwns($request, $application);
        $options = array_map(fn ($s) => $s->value, $application->status->trackingOptions());
        $data = $request->validate([
            'status' => ['required', Rule::in($options)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $application->transitionTo(ApplicationStatus::from($data['status']), $request->user(), $data['note'] ?? null);
        $this->toast('Status updated.');

        return back();
    }

    private function account(Request $request, bool $required = false): ?EmailAccount
    {
        $data = $request->validate([
            'email_account_id' => [$required ? 'required' : 'nullable', 'integer',
                Rule::exists('email_accounts', 'id')->where('user_id', $request->user()->id)],
        ]);

        return isset($data['email_account_id']) ? EmailAccount::query()->whereKey($data['email_account_id'])->first() : null;
    }

    /**
     * The thread after sending: the application email, the employer's replies (with the AI's reading
     * of each), and the user's answers (drafts, queued, sent), oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversation(Application $application): array
    {
        $messages = $application->emailMessages()->with('account:id,email,scopes')
            ->where(fn ($q) => $q->where('direction', EmailMessage::INBOUND)
                ->orWhere(fn ($q) => $q->whereNull('in_reply_to_id')->where('status', EmailMessage::SENT))
                ->orWhereNotNull('in_reply_to_id'))
            ->orderByRaw('COALESCE(received_at, sent_at, created_at)')->get();

        return $messages->map(function (EmailMessage $m) use ($application) {
            $isApplicationEmail = $m->direction === EmailMessage::OUTBOUND && $m->in_reply_to_id === null;
            $body = $isApplicationEmail ? $application->body : $m->body_text;

            return [
                'id' => $m->id,
                'kind' => match (true) {
                    $isApplicationEmail => 'application',
                    $m->direction === EmailMessage::INBOUND => 'reply',
                    default => 'answer',
                },
                'status' => $m->status,
                'from' => $m->from_email,
                'to' => $m->to_email,
                'cc' => $m->cc,
                'subject' => $isApplicationEmail ? $application->subject : $m->subject,
                'body' => $body,
                'latest_text' => $m->direction === EmailMessage::INBOUND && $body ? ReplyText::latest($body) : null,
                'snippet' => $m->snippet,
                'is_auto_reply' => $m->is_auto_reply,
                'is_bounce' => (bool) preg_match('/mailer-daemon|postmaster/i', (string) $m->from_email),
                'analysis' => $m->analysis,
                'analysis_state' => $m->analysis_state,
                'analysis_error' => $m->analysis_error,
                'in_reply_to_id' => $m->in_reply_to_id,
                'validation' => $m->validation,
                'attach_resume' => $m->attach_resume,
                'last_error' => $m->last_error,
                'scheduled_at' => $m->scheduled_at?->toIso8601String(),
                'at' => ($m->received_at ?? $m->sent_at ?? $m->created_at)->toIso8601String(),
                'can_read' => $m->account?->canReadMessages() ?? false,
                'gmail_url' => $m->thread_id && $m->account
                    ? 'https://mail.google.com/mail/u/'.rawurlencode($m->account->email).'/#all/'.$m->thread_id : null,
            ];
        })->values()->all();
    }

    private function canEditRecipients(Application $application): bool
    {
        $message = $application->outboundMessage;

        return match ($application->status) {
            ApplicationStatus::Generated, ApplicationStatus::Approved => ! $application->isGenerating(),
            ApplicationStatus::Queued => $message?->status !== EmailMessage::SENDING,
            ApplicationStatus::Sent => (bool) $message?->bounced(),
            default => false,
        };
    }

    private function canEdit(Application $application): bool
    {
        return $application->status === ApplicationStatus::Generated && ! $application->isGenerating();
    }
}
