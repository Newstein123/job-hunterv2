<?php

namespace App\Jobs;

use App\Models\JobPosting;
use App\Services\AiService\AiServiceException;
use App\Services\AiService\JobMatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Analyze one job and rate its fit (no email). Dispatched after import/add and from "Analyze". */
class AnalyzeJobPosting implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /** Not `$job`: Queueable already uses that name for the queue job itself. */
    public function __construct(public JobPosting $posting) {}

    public function uniqueId(): string
    {
        return (string) $this->posting->id;
    }

    public function backoff(): int
    {
        return 30;
    }

    /**
     * Mark jobs as waiting and queue them. (Not named `queue`: Laravel's dispatcher
     * treats a job's queue() method as a custom queueing hook.)
     */
    public static function start(JobPosting ...$jobs): void
    {
        foreach ($jobs as $job) {
            $job->forceFill(['analysis_state' => JobPosting::ANALYSIS_PENDING, 'analysis_error' => null])->save();
            self::dispatch($job);
        }
    }

    public function handle(JobMatcher $matcher): void
    {
        $this->posting->forceFill(['analysis_state' => JobPosting::ANALYSIS_RUNNING])->save();

        try {
            $matcher->analyze($this->posting);
        } catch (AiServiceException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->posting->forceFill([
            'analysis_state' => JobPosting::ANALYSIS_FAILED,
            'analysis_error' => $e instanceof AiServiceException ? $e->getMessage() : 'Analysis failed. Try again.',
        ])->save();
    }
}
