<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Services\AiService\AiServiceException;
use App\Services\AiService\ReplyAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Classify an employer's reply and pull out what it asks for. The status change is only suggested. */
class AnalyzeReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public EmailMessage $reply) {}

    public function uniqueId(): string
    {
        return (string) $this->reply->id;
    }

    public function backoff(): int
    {
        return 30;
    }

    /** Mark as pending and queue. (Not named `queue`: the dispatcher treats that as a hook.) */
    public static function start(EmailMessage $reply): void
    {
        $reply->update(['analysis_state' => 'pending', 'analysis_error' => null]);
        self::dispatch($reply);
    }

    public function handle(ReplyAssistant $assistant): void
    {
        $this->reply->update(['analysis_state' => 'running']);
        try {
            $assistant->analyze($this->reply);
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
        $this->reply->update([
            'analysis_state' => 'failed',
            'analysis_error' => $e instanceof AiServiceException ? $e->getMessage() : 'Could not analyze this reply. Try again.',
        ]);
    }
}
