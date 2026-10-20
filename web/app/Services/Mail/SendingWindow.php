<?php

namespace App\Services\Mail;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When an email is allowed to be sent, from the resolved sending rules the AI service returned
 * (timezone, allowed days, start/end time). A user constraint, not a claim about the "best" time.
 */
class SendingWindow
{
    /** @param list<string> $allowedDays */
    public function __construct(
        private readonly string $timezone,
        private readonly array $allowedDays,
        private readonly string $startTime,
        private readonly string $endTime,
    ) {}

    /** @param array{timezone?: string, allowed_days?: list<string>, start_time?: string, end_time?: string}|null $rules */
    public static function fromRules(?array $rules): self
    {
        return new self(
            $rules['timezone'] ?? 'UTC',
            $rules['allowed_days'] ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            $rules['start_time'] ?? '09:00',
            $rules['end_time'] ?? '17:00',
        );
    }

    public function allows(DateTimeInterface $moment): bool
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);
        [$start, $end] = $this->bounds($local);

        return in_array($local->englishDayOfWeek, $this->allowedDays, true) && $local >= $start && $local < $end;
    }

    /** The earliest allowed moment at or after $from, in UTC. */
    public function nextAllowed(DateTimeInterface $from): CarbonImmutable
    {
        $local = CarbonImmutable::instance($from)->setTimezone($this->timezone);

        for ($offset = 0; $offset < 8; $offset++) {
            $day = $local->addDays($offset);
            if (! in_array($day->englishDayOfWeek, $this->allowedDays, true)) {
                continue;
            }
            [$start, $end] = $this->bounds($day);
            if ($offset === 0) {
                if ($local >= $end) {
                    continue;
                }

                return ($local > $start ? $local : $start)->utc();
            }

            return $start->utc();
        }

        throw new \InvalidArgumentException('The sending rules allow no sending days.');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function bounds(CarbonImmutable $day): array
    {
        return [
            $day->setTimeFromTimeString($this->startTime),
            $day->setTimeFromTimeString($this->endTime),
        ];
    }
}
