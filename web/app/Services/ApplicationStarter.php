<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Jobs\GenerateApplicationContent;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creates the (single) application for a job and queues AI drafting. The draft
 * still has to be reviewed and approved by the user before anything is sent.
 */
class ApplicationStarter
{
    public function __construct(private readonly UsageLimits $limits) {}

    /** @throws ValidationException when the user's draft limit is reached */
    public function start(User $user, JobPosting $job): Application
    {
        $this->limits->reserveDraft($user);
        $application = new Application([
            'generation_state' => Application::GENERATION_PENDING,
            'cc' => $job->cc_emails ?: null, // CC the posting asks for
        ]);
        $application->user()->associate($user);
        $application->job()->associate($job);
        $application->transitionTo(ApplicationStatus::Draft, $user, 'Application started');

        GenerateApplicationContent::dispatch($application);

        return $application;
    }
}
