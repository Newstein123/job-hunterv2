<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Approved = 'approved';
    case Queued = 'queued';
    case Sent = 'sent';
    case Replied = 'replied';
    case Interview = 'interview';
    case Rejected = 'rejected';
    case Offer = 'offer';
    case Withdrawn = 'withdrawn';

    /**
     * Allowed next statuses. Approved -> Queued -> Sent happens through the email queue;
     * "sent" can also be recorded manually for applications the user sent themselves.
     * Queued -> Approved is cancelling a queued send.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generated, self::Withdrawn],
            self::Generated => [self::Generated, self::Approved, self::Withdrawn],
            self::Approved => [self::Generated, self::Queued, self::Sent, self::Withdrawn],
            self::Queued => [self::Approved, self::Sent, self::Withdrawn],
            // Sent -> Queued only to resend after a bounce (see EmailQueue::resend).
            self::Sent => [self::Queued, self::Replied, self::Interview, self::Rejected, self::Offer, self::Withdrawn],
            self::Replied => [self::Interview, self::Rejected, self::Offer, self::Withdrawn],
            self::Interview => [self::Rejected, self::Offer, self::Withdrawn],
            self::Offer => [self::Withdrawn],
            self::Rejected, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    /**
     * Outcome statuses the user records by hand from the application page.
     *
     * @return list<self>
     */
    public function trackingOptions(): array
    {
        if ($this === self::Queued) {
            return []; // the email queue owns this step; cancel sending first to record it by hand
        }

        $manual = [self::Sent, self::Replied, self::Interview, self::Rejected, self::Offer];

        return array_values(array_filter($this->transitions(), fn (self $s) => in_array($s, $manual, true)));
    }

    /** Content can be edited or regenerated only before approval. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Generated], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
