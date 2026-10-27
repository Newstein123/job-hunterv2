<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\AiService\AiServiceException;
use App\Services\AiService\ApplicationGenerator;
use App\Services\UsageLimits;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateApplicationContent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public Application $application) {}

    public function uniqueId(): string
    {
        return (string) $this->application->id;
    }

    public function backoff(): int
    {
        return 30;
    }

    public function handle(ApplicationGenerator $generator): void
    {
        $this->application->update(['generation_state' => Application::GENERATION_RUNNING]);

        try {
            $generator->generate($this->application);
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
        app(UsageLimits::class)->releaseDraft($this->application->user_id); // a failed draft doesn't count
        $this->application->update([
            'generation_state' => Application::GENERATION_FAILED,
            'generation_error' => $e instanceof AiServiceException ? $e->getMessage() : 'Generation failed. Please try again.',
        ]);
    }
}
