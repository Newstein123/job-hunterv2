<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\EmailAccount;
use App\Models\JobPosting;
use App\Services\ApplicationStarter;
use App\Services\Mail\EmailQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions on several selected jobs at once. Approval is deliberately not here:
 * every AI-written email is reviewed and approved on its own page first.
 */
class JobBulkController extends Controller
{
    public const MAX_SELECTION = 100;

    /**
     * Analyze the fit of selected jobs (one AI call each). Only what the user selects is analyzed,
     * at most MAX_SELECTION at a time, so a large import can't be sent to the AI by one click.
     */
    public function analyze(Request $request): RedirectResponse
    {
        if (! $request->user()->canAnalyzeJobs()) {
            throw ValidationException::withMessages(['bulk' => 'Add your career profile and an active resume first.']);
        }

        $jobs = [];
        $skipped = [];
        foreach ($this->selected($request) as $job) {
            if ($job->isSpeculative()) {
                $skipped[] = $this->skip($job, 'is a company from your list, with no posting to analyze');
            } elseif ($job->isAnalyzing()) {
                $skipped[] = $this->skip($job, 'is already being analyzed');
            } else {
                $jobs[] = $job;
            }
        }
        AnalyzeJobPosting::start(...$jobs);

        return $this->done('Analyzing '.count($jobs).' job(s)…', count($jobs), $skipped);
    }

    /** Draft applications for selected jobs that don't have one yet. */
    public function generate(Request $request, ApplicationStarter $starter): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAnalyzeJobs()) {
            throw ValidationException::withMessages(['bulk' => 'Add your career profile and an active resume first.']);
        }

        $started = 0;
        $skipped = [];
        foreach ($this->selected($request) as $job) {
            if ($job->application) {
                $skipped[] = $this->skip($job, 'already has an application');

                continue;
            }
            try {
                $starter->start($user, $job);
            } catch (ValidationException $e) {
                $skipped[] = $this->skip($job, (string) collect($e->errors())->flatten()->first());

                continue;
            }
            $started++;
        }

        return $this->done("Drafting {$started} application(s). Review each one before it can be sent.", $started, $skipped);
    }

    /**
     * Queue approved applications to send from one account, now or at a specific time. A specific time is
     * read in each job's country timezone (06:30 = 06:30 in Bangkok for Thailand, in Sydney for Australia),
     * or in one fixed timezone, and is kept exactly.
     */
    public function schedule(Request $request, EmailQueue $queue): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email_account_id' => ['required', 'integer', Rule::exists('email_accounts', 'id')->where('user_id', $user->id)],
            'mode' => ['required', Rule::in(['now', 'at'])],
            'send_at' => ['required_if:mode,at', 'nullable', 'date_format:Y-m-d\TH:i'],
            'timezone_mode' => ['required_if:mode,at', 'nullable', Rule::in(['country', 'fixed'])],
            'timezone' => ['required_if:timezone_mode,fixed', 'nullable', 'timezone:all'],
        ]);
        $account = EmailAccount::query()->whereKey($data['email_account_id'])->firstOrFail();
        $exactTime = $data['mode'] === 'at';

        $queued = [];
        $skipped = [];
        foreach ($this->selected($request) as $job) {
            $application = $job->application;
            if ($application?->status !== ApplicationStatus::Approved) {
                $skipped[] = $this->skip($job, $application ? "is {$application->status->label()}, not approved" : 'has no application yet');

                continue;
            }

            $notBefore = null;
            $tz = null;
            if ($exactTime) {
                $tz = ($data['timezone_mode'] ?? 'country') === 'fixed' ? $data['timezone'] : $this->jobTimezone($job);
                $notBefore = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['send_at'], $tz) ?: null;
                if ($notBefore === null || $notBefore->isPast()) {
                    $skipped[] = $this->skip($job, 'that time has already passed in '.$tz);

                    continue;
                }
            }

            try {
                DB::transaction(fn () => $queue->queue($application, $account, $user, $notBefore));
            } catch (ValidationException $e) {
                $skipped[] = $this->skip($job, (string) collect($e->errors())->flatten()->first());

                continue;
            }
            $label = $tz ?? 'now';
            $queued[$label] = ($queued[$label] ?? 0) + 1;
        }

        $total = array_sum($queued);
        $where = $exactTime
            ? ' at '.substr((string) $data['send_at'], 11).' local time: '.collect($queued)->map(fn ($n, $tz) => "{$n} in {$tz}")->implode(', ').'.'
            : ', sending now.';

        return $this->done("Scheduled {$total} email(s) from {$account->email}{$where}", $total, $skipped);
    }

    /** The job's country timezone, else UTC. */
    private function jobTimezone(JobPosting $job): string
    {
        return $job->country?->timezone ?? 'UTC';
    }

    /** Cancel scheduled sends; the applications go back to Approved. */
    public function cancel(Request $request, EmailQueue $queue): RedirectResponse
    {
        $cancelled = 0;
        $skipped = [];
        foreach ($this->selected($request) as $job) {
            $application = $job->application;
            if ($application?->status !== ApplicationStatus::Queued) {
                $skipped[] = $this->skip($job, 'is not scheduled');

                continue;
            }
            try {
                $queue->cancel($application, $request->user());
                $cancelled++;
            } catch (ValidationException) {
                $skipped[] = $this->skip($job, 'is being sent right now');
            }
        }

        return $this->done("Cancelled {$cancelled} scheduled email(s).", $cancelled, $skipped);
    }

    /** @return Collection<int, JobPosting> */
    private function selected(Request $request): Collection
    {
        $data = $request->validate([
            'job_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'job_ids.*' => ['integer'],
        ]);

        return $request->user()->jobPostings()->whereKey($data['job_ids'])
            ->with(['country', 'application.job', 'application.resume'])->get();
    }

    /** @return array{job: string, reason: string} */
    private function skip(JobPosting $job, string $reason): array
    {
        return ['job' => trim($job->displayTitle().($job->company ? " · {$job->company}" : '')), 'reason' => $reason];
    }

    /** @param list<array{job: string, reason: string}> $skipped */
    private function done(string $message, int $count, array $skipped): RedirectResponse
    {
        Inertia::flash('bulkResult', ['message' => $message, 'skipped' => $skipped]);
        $this->toast($message, $count === 0 ? 'warning' : ($skipped ? 'info' : 'success'));

        return back();
    }
}
