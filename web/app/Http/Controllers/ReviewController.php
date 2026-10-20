<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Batch review: read many drafts on one page, untick the ones that aren't right, approve the rest.
 * Every draft is still shown to the user in full before approval; nothing is approved unseen.
 */
class ReviewController extends Controller
{
    public const MAX_APPROVE = 100;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $kind = $request->string('kind')->toString() === JobPosting::KIND_POSTING ? JobPosting::KIND_POSTING : JobPosting::KIND_SPECULATIVE;
        $profile = $user->careerProfile?->load('targetCountries:id');

        $drafts = $user->applications()
            ->where('status', ApplicationStatus::Generated)
            ->where('generation_state', Application::GENERATION_DONE)
            ->whereHas('job', fn ($q) => $q->where('kind', $kind))
            ->with(['job.country:id,name', 'resume:id,label'])
            ->oldest()->limit(200)->get()
            ->map(fn (Application $a) => [
                'id' => $a->id,
                'job_id' => $a->job_id,
                'company' => $a->job->company,
                'position' => $a->job->position,
                'country' => $a->job->country?->name,
                'outside_targets' => (bool) $profile?->isOutsideTargets($a->job->country_id),
                'to' => $a->job->contact_email,
                'contact_flag' => $a->job->contact_flag,
                'website' => $a->job->website,
                'resume' => $a->resume?->label,
                'subject' => $a->subject,
                'body' => $a->body,
                'errors' => $a->edited_at ? [] : $a->validationErrors(),
                'warnings' => $a->validation['warnings'] ?? [],
            ]);

        $drafting = $user->applications()->where('status', ApplicationStatus::Draft)
            ->whereHas('job', fn ($q) => $q->where('kind', $kind))->count();

        return Inertia::render('review/index', [
            'kind' => $kind,
            'drafts' => $drafts,
            'drafting' => $drafting,
            'speculativeDailyLimit' => (int) config('services.mail_sending.speculative_daily_limit', 20),
        ]);
    }

    /** Approve the drafts the user left ticked. Drafts with unresolved errors must be opened and fixed first. */
    public function approve(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_APPROVE],
            'application_ids.*' => ['integer', 'distinct', Rule::exists('applications', 'id')->where('user_id', $user->id)],
        ]);

        $approved = 0;
        $skipped = 0;
        foreach (Application::whereKey($data['application_ids'])->get() as $application) {
            $ready = $application->status === ApplicationStatus::Generated && ! $application->isGenerating()
                && ($application->edited_at || $application->validationErrors() === []);
            if (! $ready) {
                $skipped++;

                continue;
            }
            $application->transitionTo(ApplicationStatus::Approved, $user, 'Approved in batch review', ['approved_at' => now()]);
            $approved++;
        }

        $note = $skipped ? " {$skipped} skipped: open them to fix their problems first." : '';
        $this->toast("Approved {$approved} email(s). Schedule them from Jobs → Approved, ready to send.{$note}", $skipped ? 'warning' : 'success');

        return back();
    }
}
