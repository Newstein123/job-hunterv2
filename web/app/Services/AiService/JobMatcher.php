<?php

namespace App\Services\AiService;

use App\Models\JobPosting;
use App\Models\Resume;
use Illuminate\Support\Facades\DB;

/**
 * Analyzes a job and rates its fit against the user's profile, without drafting an email.
 */
class JobMatcher
{
    public function __construct(
        private readonly AiServiceClient $client,
        private readonly AnalysisRecorder $recorder,
        private readonly UsageRecorder $usage,
    ) {}

    public function analyze(JobPosting $job): void
    {
        $job->loadMissing(['user.careerProfile', 'user.applicationRule']);
        $user = $job->user;
        $resumes = $user->resumes()->where('is_active', true)->get();
        if (! $user->careerProfile || $resumes->isEmpty()) {
            throw new AiServiceException('Add your career profile and an active resume to analyze jobs', 422);
        }

        $profileVersion = $user->profileVersion();
        $result = $this->client->matchJob(PipelinePayload::forApplication($user, $job, $resumes));

        DB::transaction(function () use ($job, $result, $resumes, $profileVersion, $user) {
            $this->recorder->record($job, $result, $resumes->map(fn (Resume $r) => $r->id)->values()->all(), $profileVersion);
            $this->usage->record($result['meta']['usage'] ?? null, $user->id, $job->id);
        });
    }
}
