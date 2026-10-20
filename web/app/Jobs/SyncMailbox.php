<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use App\Services\Mail\ThreadMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks the threads of sent applications for new messages from the employer. Replies are stored
 * (with their text when the account allows reading), auto-replies and bounces are recognised,
 * and real replies move the application to Replied and get analyzed.
 */
class SyncMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Only look at applications sent within this many days. */
    public const LOOKBACK_DAYS = 60;

    /** Conversations can continue after the first reply (scheduling an interview, an offer…). */
    private const TRACKED = [ApplicationStatus::Sent, ApplicationStatus::Replied, ApplicationStatus::Interview, ApplicationStatus::Offer];

    public function __construct(public EmailAccount $account) {}

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function handle(MailProvider $provider): void
    {
        $account = $this->account->fresh();
        if ($account === null || $account->status === EmailAccount::STATUS_NEEDS_REAUTH) {
            return;
        }

        if ($account->canReadMessages()) {
            $this->backfillText($account, $provider);
        }

        // One per thread: the first outbound message we sent into it (the application email).
        $threads = $account->messages()
            ->where('direction', EmailMessage::OUTBOUND)->where('status', EmailMessage::SENT)
            ->whereNull('in_reply_to_id')
            ->whereNotNull('thread_id')->where('sent_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereHas('application', fn ($q) => $q->whereIn('status', self::TRACKED))
            ->with('application')->get();

        foreach ($threads as $outbound) {
            try {
                $messages = $provider->threadMessages($account, (string) $outbound->thread_id);
            } catch (MailProviderException $e) {
                if ($e->kind === MailProviderException::AUTH) {
                    $account->markUnhealthy(EmailAccount::STATUS_NEEDS_REAUTH, $e->getMessage());

                    return;
                }

                continue; // thread deleted or a temporary error; try again next sync
            }
            $this->process($account, $outbound, $messages, $provider);
        }

        $account->forceFill(['last_synced_at' => now()])->save();
    }

    /**
     * Messages recorded before the account could read mail (headers only) get their text now,
     * and real replies among them get analyzed.
     */
    private function backfillText(EmailAccount $account, MailProvider $provider): void
    {
        $missing = $account->messages()->where('direction', EmailMessage::INBOUND)->whereNull('body_text')
            ->whereNotNull('provider_message_id')->where('received_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->limit(20)->get();

        foreach ($missing as $inbound) {
            try {
                $full = $provider->message($account, (string) $inbound->provider_message_id);
            } catch (MailProviderException) {
                continue;
            }
            $inbound->update([
                'body_text' => $full->bodyText !== '' ? $full->bodyText : null,
                'snippet' => $full->snippet,
                'subject' => $inbound->subject ?? mb_substr($full->subject, 0, 255),
                'rfc_message_id' => $inbound->rfc_message_id ?? ($full->headers['message-id'] ?? null),
            ]);
            $isBounce = (bool) preg_match('/mailer-daemon|postmaster/i', (string) $inbound->from_email);
            if ($inbound->body_text && ! $isBounce && ! $inbound->is_auto_reply && ! $inbound->analysis) {
                AnalyzeReply::start($inbound);
            }
        }
    }

    /** @param list<ThreadMessage> $messages */
    private function process(EmailAccount $account, EmailMessage $outbound, array $messages, MailProvider $provider): void
    {
        foreach ($messages as $m) {
            if ($m->isFromMe() || $m->receivedAt->lt($outbound->sent_at)) {
                continue;
            }
            if ($account->messages()->where('provider_message_id', $m->id)->exists()) {
                continue;
            }

            $full = null;
            if ($account->canReadMessages()) {
                try {
                    $full = $provider->message($account, $m->id);
                } catch (MailProviderException) {
                    // keep the headers-only record; the text can be fetched on a later sync
                }
            }
            $auto = $m->isAutoReply();

            $inbound = $account->messages()->create([
                'user_id' => $outbound->user_id,
                'application_id' => $outbound->application_id,
                'direction' => EmailMessage::INBOUND,
                'status' => EmailMessage::RECEIVED,
                'from_email' => mb_substr($m->from, 0, 255),
                'subject' => $full ? mb_substr($full->subject, 0, 255) : ($m->headers['subject'] ?? null),
                'body_text' => $full?->bodyText,
                'snippet' => $full?->snippet,
                'provider_message_id' => $m->id,
                'thread_id' => $outbound->thread_id,
                'rfc_message_id' => $full->headers['message-id'] ?? $m->headers['message-id'] ?? null,
                'is_auto_reply' => $auto,
                'received_at' => $m->receivedAt,
            ]);

            if ($m->isBounce()) {
                $outbound->update(['last_error' => EmailMessage::BOUNCE_PREFIX.": bounce from {$m->from}"]);
                $outbound->recordEvent('bounce_detected', ['from' => $m->from]);

                continue;
            }
            if ($auto) {
                $outbound->recordEvent('auto_reply_detected', ['from' => $m->from]);

                continue; // an out-of-office is not a reply from the employer
            }

            $outbound->recordEvent('reply_detected', ['from' => $m->from]);
            $application = $outbound->application->refresh();
            if ($application->status === ApplicationStatus::Sent) {
                $application->transitionTo(ApplicationStatus::Replied, note: "Reply from {$m->from}");
            }
            if ($inbound->body_text) {
                AnalyzeReply::start($inbound);
            }
        }
    }
}
