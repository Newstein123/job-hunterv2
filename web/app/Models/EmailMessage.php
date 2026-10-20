<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound application email moving through the queue, or an inbound reply found by mailbox sync.
 *
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property int|null $email_account_id
 * @property string $direction
 * @property string $status
 * @property string|null $to_email
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property string|null $from_email
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $snippet
 * @property string|null $rfc_message_id
 * @property bool $is_auto_reply
 * @property array<string, mixed>|null $analysis
 * @property string|null $analysis_state
 * @property string|null $analysis_error
 * @property int|null $in_reply_to_id
 * @property bool $attach_resume
 * @property array{errors?: list<string>, warnings?: list<string>, attempts?: int}|null $validation
 * @property-read EmailMessage|null $inReplyTo
 * @property string|null $provider_message_id
 * @property string|null $thread_id
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $received_at
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property-read Application $application
 * @property-read EmailAccount|null $account
 */
#[Fillable([
    'user_id', 'application_id', 'in_reply_to_id', 'email_account_id', 'direction', 'status', 'to_email', 'cc', 'bcc', 'from_email', 'subject',
    'body_text', 'snippet', 'rfc_message_id', 'is_auto_reply', 'analysis', 'analysis_state', 'analysis_error', 'attach_resume', 'validation',
    'provider_message_id', 'thread_id', 'scheduled_at', 'sent_at', 'received_at', 'attempts', 'last_error',
])]
class EmailMessage extends Model
{
    public const OUTBOUND = 'outbound';

    public const INBOUND = 'inbound';

    public const QUEUED = 'queued';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const RETRYING = 'retrying';

    public const CANCELLED = 'cancelled';

    public const RECEIVED = 'received';

    /** An AI-drafted reply waiting for the user's review. */
    public const DRAFT = 'draft';

    /** Outbound states that still count as "this application is being sent". */
    public const ACTIVE_STATES = [self::QUEUED, self::SCHEDULED, self::SENDING, self::RETRYING];

    public const MAX_ATTEMPTS = 3;

    /** last_error prefix set by mailbox sync when a sent email bounces. */
    public const BOUNCE_PREFIX = 'Delivery failed';

    public function bounced(): bool
    {
        return $this->status === self::SENT && str_starts_with((string) $this->last_error, self::BOUNCE_PREFIX);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'is_auto_reply' => 'boolean',
            'attach_resume' => 'boolean',
            'analysis' => 'array',
            'validation' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    /** @return BelongsTo<EmailMessage, $this> */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'in_reply_to_id');
    }

    public function isReplyDraft(): bool
    {
        return $this->direction === self::OUTBOUND && $this->in_reply_to_id !== null;
    }

    /** @return HasMany<EmailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    /** @param array<string, mixed> $payload */
    public function recordEvent(string $type, array $payload = []): void
    {
        $this->events()->create(['email_account_id' => $this->email_account_id, 'type' => $type, 'payload' => $payload ?: null]);
    }
}
