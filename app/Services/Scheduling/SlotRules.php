<?php

namespace App\Services\Scheduling;

use App\Models\EventType;

/**
 * The per-event-type constraints the slot engine applies.
 */
final class SlotRules
{
    public function __construct(
        public readonly int $durationMinutes,
        public readonly ?int $intervalMinutes = null,
        public readonly int $bufferBeforeMinutes = 0,
        public readonly int $bufferAfterMinutes = 0,
        public readonly int $minNoticeMinutes = 0,
        public readonly int $maxDaysAhead = 60,
        public readonly ?int $dailyLimit = null,
    ) {}

    public static function fromEventType(EventType $eventType): self
    {
        return new self(
            durationMinutes: $eventType->duration_minutes,
            intervalMinutes: $eventType->slot_interval_minutes,
            bufferBeforeMinutes: $eventType->buffer_before_minutes,
            bufferAfterMinutes: $eventType->buffer_after_minutes,
            minNoticeMinutes: $eventType->min_notice_minutes,
            maxDaysAhead: $eventType->max_days_ahead,
            dailyLimit: $eventType->daily_limit,
        );
    }

    /**
     * Minutes between consecutive start times (defaults to the duration).
     */
    public function step(): int
    {
        return $this->intervalMinutes ?: $this->durationMinutes;
    }
}
