<?php

namespace App\Jobs;

use App\Models\Resume;
use App\Services\AiService\AiServiceClient;
use App\Services\AiService\AiServiceException;
use App\Services\AiService\UsageRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** CV check after upload: the course checklist plus a few AI suggestions. The file is never changed. */
class ReviewResume implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Resume $resume) {}

    public function uniqueId(): string
    {
        return (string) $this->resume->id;
    }

    public function backoff(): int
    {
        return 30;
    }

    public static function start(Resume $resume): void
    {
        $resume->saveReview(['review_state' => Resume::REVIEW_PENDING, 'review_error' => null]);
        self::dispatch($resume);
    }

    public function handle(AiServiceClient $client, UsageRecorder $usage): void
    {
        $this->resume->saveReview(['review_state' => Resume::REVIEW_RUNNING]);
        $user = $this->resume->user()->with(['careerProfile', 'applicationRule'])->firstOrFail();

        try {
            $review = $client->reviewResume([
                'resume' => [
                    'id' => (string) $this->resume->id,
                    'filename' => $this->resume->original_filename,
                    'content_base64' => base64_encode((string) Storage::disk(Resume::disk())->get($this->resume->path)),
                ],
                'candidate_name' => $user->careerProfile->full_name ?? $user->name,
                'user_rules' => $user->applicationRule?->rules ?: null,
            ]);
        } catch (AiServiceException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }
            throw $e;
        }

        $usage->record($review['usage'] ?? null, $user->id);
        unset($review['usage']);
        $this->resume->saveReview([
            'review_state' => Resume::REVIEW_DONE, 'review' => $review, 'review_error' => null, 'reviewed_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $this->resume->saveReview([
            'review_state' => Resume::REVIEW_FAILED,
            'review_error' => $e instanceof AiServiceException ? mb_substr($e->getMessage(), 0, 500) : 'The CV check failed. Try again.',
        ]);
    }
}
