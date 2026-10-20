<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $user_id
 * @property int $job_id
 * @property int|null $resume_id
 * @property int|null $email_account_id
 * @property ApplicationStatus $status
 * @property string $generation_state
 * @property string|null $generation_error
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $cover_letter
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property array{errors?: list<string>, warnings?: list<string>, attempts?: int}|null $validation
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read JobPosting $job
 * @property-read Resume|null $resume
 * @property-read EmailAccount|null $emailAccount
 * @property-read EmailMessage|null $outboundMessage
 * @property-read ApplicationStrategy|null $latestStrategy
 * @property-read Collection<int, ApplicationStatusHistory> $statusHistory
 */
#[Fillable([
    'resume_id', 'email_account_id', 'status', 'generation_state', 'generation_error', 'subject', 'body', 'cover_letter', 'cc', 'bcc',
    'validation', 'edited_at', 'approved_at',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    public const GENERATION_PENDING = 'pending';

    public const GENERATION_RUNNING = 'running';

    public const GENERATION_FAILED = 'failed';

    public const GENERATION_DONE = 'done';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'validation' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'edited_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<JobPosting, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    /** @return BelongsTo<Resume, $this> */
    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /** @return HasMany<EmailMessage, $this> */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * The latest attempt to send the application email itself (queued, sent, failed or cancelled).
     * Answers to the employer's replies are not included; see replyState().
     *
     * @return HasOne<EmailMessage, $this>
     */
    public function outboundMessage(): HasOne
    {
        // The filters must be inside ofMany: an outer where() would pick the newest message of any kind
        // (an inbound reply, or our answer to it) and then discard it, returning the wrong row or nothing.
        return $this->hasOne(EmailMessage::class)->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->where('direction', EmailMessage::OUTBOUND)->whereNull('in_reply_to_id'),
        );
    }

    /**
     * Where the conversation stands after sending, from the user's side:
     * awaiting_you (the employer's latest message is unanswered), reply_draft, reply_scheduled,
     * you_replied, or null when there's no reply from the employer yet.
     *
     * @return array{state: string, at: string|null}|null
     */
    public function replyState(): ?array
    {
        $messages = $this->relationLoaded('emailMessages')
            ? $this->emailMessages
            : $this->emailMessages()->get(['id', 'direction', 'status', 'in_reply_to_id', 'is_auto_reply', 'from_email', 'received_at', 'sent_at', 'created_at']);

        $lastReply = $messages
            ->filter(fn (EmailMessage $m) => $m->direction === EmailMessage::INBOUND && ! $m->is_auto_reply
                && ! preg_match('/mailer-daemon|postmaster/i', (string) $m->from_email))
            ->sortBy('received_at')->last();
        if ($lastReply === null) {
            return null;
        }
        $answers = $messages->filter(fn (EmailMessage $m) => $m->in_reply_to_id !== null);
        $sent = $answers->filter(fn (EmailMessage $m) => $m->status === EmailMessage::SENT && $m->sent_at?->gte($lastReply->received_at))
            ->sortBy('sent_at')->last();
        if ($sent) {
            return ['state' => 'you_replied', 'at' => $sent->sent_at?->toIso8601String()];
        }
        $pending = $answers->where('in_reply_to_id', $lastReply->id);
        if ($pending->contains(fn (EmailMessage $m) => in_array($m->status, EmailMessage::ACTIVE_STATES, true))) {
            return ['state' => 'reply_scheduled', 'at' => null];
        }
        if ($pending->contains(fn (EmailMessage $m) => $m->status === EmailMessage::DRAFT)) {
            return ['state' => 'reply_draft', 'at' => null];
        }

        return ['state' => 'awaiting_you', 'at' => $lastReply->received_at?->toIso8601String()];
    }

    /** @return HasMany<ApplicationStrategy, $this> */
    public function strategies(): HasMany
    {
        return $this->hasMany(ApplicationStrategy::class);
    }

    /** @return HasOne<ApplicationStrategy, $this> */
    public function latestStrategy(): HasOne
    {
        return $this->hasOne(ApplicationStrategy::class)->latestOfMany();
    }

    /** @return HasMany<ApplicationStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderByDesc('id');
    }

    public function isGenerating(): bool
    {
        return in_array($this->generation_state, [self::GENERATION_PENDING, self::GENERATION_RUNNING], true);
    }

    /** @return list<string> */
    public function validationErrors(): array
    {
        return $this->validation['errors'] ?? [];
    }

    /**
     * Change status and record it in the history. Same-status "transitions" (e.g. regenerating)
     * are recorded too, so the history shows every generation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(ApplicationStatus $next, ?User $by = null, ?string $note = null, array $attributes = []): void
    {
        $current = $this->exists ? $this->status : null;
        if ($current !== null && ! $current->canTransitionTo($next)) {
            throw new InvalidArgumentException("Cannot move application from {$current->value} to {$next->value}");
        }

        DB::transaction(function () use ($current, $next, $by, $note, $attributes) {
            $this->fill([...$attributes, 'status' => $next])->save();
            $this->statusHistory()->create([
                'user_id' => $by?->id,
                'from_status' => $current?->value,
                'to_status' => $next->value,
                'note' => $note,
            ]);
        });
    }
}
