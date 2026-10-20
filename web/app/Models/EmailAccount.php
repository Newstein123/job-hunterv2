<?php

namespace App\Models;

use App\Services\Mail\GmailProvider;
use Carbon\CarbonImmutable;
use Database\Factories\EmailAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mailbox the user connected. One user can connect several accounts; each has its own
 * daily limit and health. Accounts are never rotated to get around provider limits.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $email
 * @property string $auth_type
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property list<string>|null $scopes
 * @property string $status
 * @property string|null $status_reason
 * @property int $daily_limit
 * @property int $sent_today
 * @property CarbonImmutable|null $usage_date
 * @property CarbonImmutable|null $last_sent_at
 * @property CarbonImmutable|null $last_synced_at
 * @property bool $is_default
 * @property array<string, mixed>|null $metadata
 * @property-read User $user
 */
#[Fillable([
    'provider', 'email', 'auth_type', 'access_token', 'refresh_token', 'token_expires_at', 'scopes',
    'status', 'status_reason', 'daily_limit', 'sent_today', 'usage_date', 'last_sent_at', 'last_synced_at',
    'is_default', 'metadata',
])]
#[Hidden(['access_token', 'refresh_token', 'metadata'])]
class EmailAccount extends Model
{
    /** @use HasFactory<EmailAccountFactory> */
    use HasFactory;

    public const PROVIDER_GMAIL = 'gmail';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_RESTRICTED = 'restricted';

    public const STATUS_DISABLED = 'disabled';

    /** Conservative default; the user can raise it up to the provider's documented limit. */
    public const DEFAULT_DAILY_LIMIT = 20;

    public const MAX_DAILY_LIMIT = 500;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'usage_date' => 'date',
            'last_sent_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<EmailMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /** Connected with read permission, so reply text can be shown and analyzed. */
    public function canReadMessages(): bool
    {
        return in_array(GmailProvider::READ_SCOPE, $this->scopes ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Sends counted for today (UTC); resets when the day changes. */
    public function sentToday(): int
    {
        return $this->usage_date?->isToday() ? $this->sent_today : 0;
    }

    public function hasCapacity(): bool
    {
        return $this->sentToday() < $this->daily_limit;
    }

    /** First emails of speculative applications sent from this account since midnight (replies don't count). */
    public function speculativeSentToday(): int
    {
        return $this->messages()->where('direction', EmailMessage::OUTBOUND)->whereNull('in_reply_to_id')
            ->where('status', EmailMessage::SENT)->where('sent_at', '>=', now()->startOfDay())
            ->whereHas('application.job', fn ($q) => $q->where('kind', JobPosting::KIND_SPECULATIVE))
            ->count();
    }

    public function hasSpeculativeCapacity(): bool
    {
        return $this->speculativeSentToday() < (int) config('services.mail_sending.speculative_daily_limit', 20);
    }

    public function recordSend(): void
    {
        $this->forceFill([
            'sent_today' => $this->sentToday() + 1,
            'usage_date' => now()->toDateString(),
            'last_sent_at' => now(),
        ])->save();
    }

    /** Stop using the account and tell the user why. */
    public function markUnhealthy(string $status, string $reason): void
    {
        $this->forceFill(['status' => $status, 'status_reason' => $reason])->save();
        EmailEvent::create([
            'email_account_id' => $this->id,
            'type' => 'account_error',
            'payload' => ['status' => $status, 'reason' => $reason],
        ]);
    }
}
