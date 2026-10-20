<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Mail\EmailComposer;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use App\Services\Mail\RecipientValidator;
use App\Services\Mail\SendingWindow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one approved application email. Dispatched only by `emails:dispatch-due`.
 */
class SendEmailMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Retries are managed through the message state (retrying + scheduled_at), not queue retries. */
    public int $tries = 1;

    public int $timeout = 120;

    /** Minutes to wait before retrying a transient failure, per attempt. */
    private const RETRY_DELAYS = [15, 60];

    public function __construct(public EmailMessage $message) {}

    public function uniqueId(): string
    {
        return (string) $this->message->id;
    }

    public function handle(MailProvider $provider, EmailComposer $composer, RecipientValidator $recipients): void
    {
        $message = $this->message->fresh(['application.job', 'application.latestStrategy', 'account']);
        if ($message === null || $message->status !== EmailMessage::SENDING) {
            return;
        }
        $application = $message->application;
        $account = $message->account;

        $isReply = $message->isReplyDraft();
        if (! $isReply && $application->status !== ApplicationStatus::Queued) {
            $message->update(['status' => EmailMessage::CANCELLED, 'last_error' => 'Application is no longer queued']);

            return;
        }
        if ($account === null || ! $account->isActive()) {
            $this->fail($message, 'The sending account is not available: '.($account->status_reason ?? 'disconnected'));

            return;
        }

        $window = SendingWindow::fromRules($application->latestStrategy?->application_strategy['sending_rules'] ?? null);
        // An exact time the user picked is honoured even outside the window; the daily limit always applies.
        if ($message->respect_window && ! $window->allows(now())) {
            $message->update(['status' => EmailMessage::SCHEDULED, 'scheduled_at' => $window->nextAllowed(now())]);

            return;
        }
        if (! $account->hasCapacity()) {
            $next = $message->respect_window
                ? $window->nextAllowed(now()->utc()->addDay()->startOfDay())
                : ($message->scheduled_at ?? now())->addDay(); // same local time tomorrow
            $message->update(['status' => EmailMessage::SCHEDULED, 'scheduled_at' => $next]);

            return;
        }

        // Re-check recipients at send time: something may have bounced since this was queued.
        $problems = $recipients->problemsForAll(
            [(string) $message->to_email, ...$message->cc ?? [], ...$message->bcc ?? []], $message->user_id,
        );
        if ($problems !== []) {
            $this->fail($message, 'Not sent: '.implode('; ', $problems).'.');

            return;
        }

        $message->increment('attempts');

        try {
            $sent = $isReply
                ? $provider->send($account, $composer->composeReply($message, $account), $message->thread_id)
                : $provider->send($account, $composer->compose($application, $account));
        } catch (MailProviderException $e) {
            $this->handleFailure($message, $account, $e);

            return;
        }

        $message->update([
            'status' => EmailMessage::SENT,
            'provider_message_id' => $sent->id,
            'thread_id' => $sent->threadId,
            'sent_at' => now(),
            'last_error' => null,
        ]);
        $message->recordEvent('sent', ['provider_message_id' => $sent->id]);
        $account->recordSend();
        if ($isReply) {
            $application->statusHistory()->create([
                'from_status' => $application->status->value, 'to_status' => $application->status->value,
                'note' => "Replied to {$message->to_email}",
            ]);

            return;
        }
        $application->transitionTo(ApplicationStatus::Sent, note: "Sent from {$account->email} to {$message->to_email}");
    }

    private function handleFailure(EmailMessage $message, EmailAccount $account, MailProviderException $e): void
    {
        match ($e->kind) {
            MailProviderException::AUTH => $account->markUnhealthy(EmailAccount::STATUS_NEEDS_REAUTH, $e->getMessage()),
            MailProviderException::RESTRICTED => $account->markUnhealthy(EmailAccount::STATUS_RESTRICTED, $e->getMessage()),
            default => null,
        };

        if ($e->kind === MailProviderException::TRANSIENT && $message->attempts < EmailMessage::MAX_ATTEMPTS) {
            $delay = self::RETRY_DELAYS[$message->attempts - 1] ?? 60;
            $message->update([
                'status' => EmailMessage::RETRYING,
                'scheduled_at' => now()->addMinutes($delay),
                'last_error' => $e->getMessage(),
            ]);
            $message->recordEvent('retrying', ['error' => $e->getMessage(), 'in_minutes' => $delay]);

            return;
        }

        $this->fail($message, $e->getMessage());
    }

    private function fail(EmailMessage $message, string $error): void
    {
        $message->update(['status' => EmailMessage::FAILED, 'last_error' => $error]);
        $message->recordEvent('failed', ['error' => $error]);
    }
}
