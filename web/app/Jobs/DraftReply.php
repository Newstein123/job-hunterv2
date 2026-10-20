<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Services\AiService\AiServiceException;
use App\Services\AiService\ReplyAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Fill an AI draft answer to an employer's reply. The user reviews it before it can be sent. */
class DraftReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public EmailMessage $draft, public string $notes = '') {}

    public function backoff(): int
    {
        return 20;
    }

    public function handle(ReplyAssistant $assistant): void
    {
        $draft = $this->draft->fresh();
        if ($draft === null || $draft->status !== EmailMessage::DRAFT) {
            return; // discarded or replaced meanwhile
        }
        try {
            $assistant->fill($draft, $this->notes);
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
        $this->draft->fresh()?->update([
            'last_error' => $e instanceof AiServiceException ? $e->getMessage() : 'Drafting failed. Try again.',
        ]);
    }
}
