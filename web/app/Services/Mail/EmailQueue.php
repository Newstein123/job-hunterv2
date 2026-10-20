<?php

namespace App\Services\Mail;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All outgoing application emails go through here. Queueing never sends: the scheduler
 * dispatches due messages to a background job (see SendEmailMessage).
 */
class EmailQueue
{
    public function __construct(private readonly RecipientValidator $recipients) {}

    /**
     * @param  CarbonInterface|null  $notBefore  a time the user picked
     * @param  bool  $respectWindow  false: send at exactly $notBefore even outside the sending window
     *                               (the user's explicit choice wins); true: move it to the next allowed slot
     */
    public function queue(
        Application $application,
        EmailAccount $account,
        User $by,
        ?CarbonInterface $notBefore = null,
        bool $respectWindow = true,
    ): EmailMessage {
        $application->loadMissing(['job', 'resume', 'latestStrategy']);
        $this->assertSendable($application, $account);

        $window = SendingWindow::fromRules($application->latestStrategy?->application_strategy['sending_rules'] ?? null);
        $exact = $notBefore !== null && ! $respectWindow;
        $from = $notBefore !== null && $notBefore->isFuture() ? $notBefore : now();
        $scheduledAt = $exact ? CarbonImmutable::instance($from)->utc() : $window->nextAllowed($from);

        return DB::transaction(function () use ($application, $account, $by, $scheduledAt, $exact) {
            $message = $application->emailMessages()->create([
                'user_id' => $application->user_id,
                'email_account_id' => $account->id,
                'direction' => EmailMessage::OUTBOUND,
                'status' => $scheduledAt->isFuture() ? EmailMessage::SCHEDULED : EmailMessage::QUEUED,
                'to_email' => $application->job->contact_email,
                'cc' => $application->cc ?: null,
                'bcc' => $application->bcc ?: null,
                'from_email' => $account->email,
                'subject' => $application->subject,
                'scheduled_at' => $scheduledAt,
                'respect_window' => ! $exact,
            ]);
            $message->recordEvent('queued', ['scheduled_at' => $scheduledAt->toIso8601String()]);
            $application->transitionTo(ApplicationStatus::Queued, $by, "Queued to send from {$account->email}", [
                'email_account_id' => $account->id,
            ]);

            return $message;
        });
    }

    /** Cancel a queued send; the application goes back to Approved. */
    public function cancel(Application $application, ?User $by, string $note = 'Sending cancelled'): void
    {
        DB::transaction(function () use ($application, $by, $note) {
            $message = $application->outboundMessage;
            if ($message && in_array($message->status, [...EmailMessage::ACTIVE_STATES, EmailMessage::FAILED], true)) {
                if ($message->status === EmailMessage::SENDING) {
                    throw ValidationException::withMessages(['send' => 'This email is being sent right now.']);
                }
                $message->update(['status' => EmailMessage::CANCELLED]);
                $message->recordEvent('cancelled');
            }
            if ($application->status === ApplicationStatus::Queued) {
                $application->transitionTo(ApplicationStatus::Approved, $by, $note);
            }
        });
    }

    /**
     * Queue a reviewed reply. It goes into the same Gmail thread, at the earliest time the
     * sending window allows. The application's status is not changed by replying.
     */
    public function queueReply(EmailMessage $draft, EmailAccount $account, User $by): EmailMessage
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $draft->loadMissing(['application.latestStrategy', 'inReplyTo']);

        if (! $draft->isReplyDraft() || $draft->status !== EmailMessage::DRAFT) {
            $fail('send', 'Only a reply draft can be sent.');
        }
        if ($draft->body_text === null || trim($draft->body_text) === '') {
            $fail('body', 'The reply is empty.');
        }
        if (str_contains((string) $draft->body_text, '[[')) {
            $fail('body', 'Fill in every [[...]] placeholder before sending.');
        }
        if ($account->user_id !== $draft->user_id || ! $account->isActive()) {
            $fail('email_account_id', "{$account->email} can't send right now.");
        }
        $problems = $this->recipients->problemsForAll([(string) $draft->to_email, ...$draft->cc ?? [], ...$draft->bcc ?? []], $draft->user_id);
        if ($problems !== []) {
            $fail('recipient', "Can't send: ".implode('; ', $problems).'.');
        }

        $window = SendingWindow::fromRules($draft->application->latestStrategy?->application_strategy['sending_rules'] ?? null);
        $scheduledAt = $window->nextAllowed(now());
        $draft->update([
            'email_account_id' => $account->id,
            'from_email' => $account->email,
            'status' => $scheduledAt->isFuture() ? EmailMessage::SCHEDULED : EmailMessage::QUEUED,
            'scheduled_at' => $scheduledAt,
            'respect_window' => true,
        ]);
        $draft->recordEvent('reply_queued', ['by' => $by->id, 'scheduled_at' => $scheduledAt->toIso8601String()]);

        return $draft;
    }

    /** Send again after a bounce, e.g. to a corrected address. */
    public function resend(Application $application, EmailAccount $account, User $by): EmailMessage
    {
        if (! $application->outboundMessage?->bounced()) {
            throw ValidationException::withMessages(['send' => 'Only bounced emails can be resent.']);
        }

        return $this->queue($application, $account, $by);
    }

    /** Re-queue a failed message, optionally from a different account. */
    public function retry(Application $application, EmailAccount $account, User $by): EmailMessage
    {
        $this->cancel($application, $by, 'Retrying send');

        return $this->queue($application->refresh(), $account, $by);
    }

    private function assertSendable(Application $application, EmailAccount $account): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        $resendingBounce = $application->status === ApplicationStatus::Sent && $application->outboundMessage?->bounced();
        if ($application->status !== ApplicationStatus::Approved && ! $resendingBounce) {
            $fail('send', 'Only approved applications can be queued.');
        }
        if ($account->user_id !== $application->user_id) {
            $fail('email_account_id', 'Choose one of your connected accounts.');
        }
        if (! $account->isActive()) {
            $fail('email_account_id', "{$account->email} can't send right now: ".($account->status_reason ?? $account->status));
        }

        $recipient = $application->job->contact_email;
        if (! $recipient) {
            $fail('recipient', 'This job has no contact email. Add the address the posting gives.');
        }
        // Pre-send checks on every address: format, test domains, own accounts, past bounces, mail servers.
        $problems = $this->recipients->problemsForAll([(string) $recipient, ...$application->cc ?? [], ...$application->bcc ?? []], $application->user_id);
        if ($problems !== []) {
            $fail('recipient', "Can't send: ".implode('; ', $problems).'.');
        }
        if (! $application->resume) {
            $fail('resume_id', 'Choose a resume to attach.');
        }

        // Duplicate protection: one active send per application, and never the same email twice.
        // A bounced email never arrived, so it doesn't count.
        $delivered = fn ($q) => $q->where('direction', EmailMessage::OUTBOUND)
            ->where(fn ($q) => $q->whereIn('status', EmailMessage::ACTIVE_STATES)
                ->orWhere(fn ($q) => $q->where('status', EmailMessage::SENT)
                    ->where(fn ($q) => $q->whereNull('last_error')->orWhere('last_error', 'not like', EmailMessage::BOUNCE_PREFIX.'%'))));
        if ($application->emailMessages()->where($delivered)->exists()) {
            $fail('send', 'This application already has an email queued or sent.');
        }
        $sameEmail = EmailMessage::where('user_id', $application->user_id)->where($delivered)
            ->where('to_email', $recipient)->where('subject', $application->subject)->exists();
        if ($sameEmail) {
            $fail('send', 'You already sent an email with this subject to this recipient.');
        }
    }
}
