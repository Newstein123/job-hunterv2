<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Per-user caps for the testing period (config/limits.php): AI drafts and application emails sent.
 * A limit of 0 means unlimited.
 */
class UsageLimits
{
    public function draftLimit(): ?int
    {
        return $this->limit('limits.drafts_per_user');
    }

    public function sendLimit(): ?int
    {
        return $this->limit('limits.sends_per_user');
    }

    public function draftsUsed(User $user): int
    {
        return (int) User::whereKey($user->id)->value('ai_drafts_used');
    }

    /** Application emails sent or on their way (queued, scheduled, sending, retrying). Replies don't count. */
    public function sendsUsed(User $user): int
    {
        return EmailMessage::where('user_id', $user->id)->where('direction', EmailMessage::OUTBOUND)
            ->whereNull('in_reply_to_id')
            ->whereIn('status', [...EmailMessage::ACTIVE_STATES, EmailMessage::SENT])
            ->count();
    }

    /** Take one draft from the allowance, atomically. False when the limit is reached. */
    public function tryReserveDraft(User $user): bool
    {
        $limit = $this->draftLimit();
        $query = User::whereKey($user->id);
        if ($limit !== null) {
            $query->where('ai_drafts_used', '<', $limit);
        }

        return $query->increment('ai_drafts_used') > 0;
    }

    /** @throws ValidationException when the draft limit is reached */
    public function reserveDraft(User $user, string $field = 'application'): void
    {
        if (! $this->tryReserveDraft($user)) {
            throw ValidationException::withMessages([$field => $this->draftLimitMessage()]);
        }
    }

    /** Give a draft back, e.g. when generation failed. */
    public function releaseDraft(int $userId): void
    {
        User::whereKey($userId)->where('ai_drafts_used', '>', 0)->decrement('ai_drafts_used');
    }

    /** @throws ValidationException when the send limit is reached */
    public function assertCanSend(User $user): void
    {
        $limit = $this->sendLimit();
        if ($limit !== null && $this->sendsUsed($user) >= $limit) {
            throw ValidationException::withMessages([
                'send' => "You've reached the testing limit of {$limit} application emails. Contact us if you need more.",
            ]);
        }
    }

    public function draftLimitMessage(): string
    {
        return "You've used all {$this->draftLimit()} AI drafts included in testing. Contact us if you need more.";
    }

    /** @return array{drafts: array{used: int, limit: int|null}, sends: array{used: int, limit: int|null}} */
    public function summary(User $user): array
    {
        return [
            'drafts' => ['used' => $this->draftsUsed($user), 'limit' => $this->draftLimit()],
            'sends' => ['used' => $this->sendsUsed($user), 'limit' => $this->sendLimit()],
        ];
    }

    private function limit(string $key): ?int
    {
        $value = (int) config($key, 0);

        return $value > 0 ? $value : null;
    }
}
