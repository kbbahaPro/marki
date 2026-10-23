<?php

namespace App\Services\Scheduling;

use App\Models\Schedule;
use Carbon\CarbonImmutable;

/**
 * A host's working hours in their own time zone: weekly ranges plus
 * per-date overrides. Ranges are [startMinute, endMinute] since local
 * midnight, where 1440 is the end of the day.
 */
final class WeeklyAvailability
{
    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $weekly  Weekday (0 = Sunday) => ranges.
     * @param  array<string, list<array{0: int, 1: int}>>  $overrides  'Y-m-d' => ranges; an empty list means unavailable.
     */
    public function __construct(
        public readonly string $timezone,
        public readonly array $weekly,
        public readonly array $overrides = [],
    ) {}

    /**
     * Build from a stored schedule, loading only the overrides inside $within (if given).
     */
    public static function fromSchedule(Schedule $schedule, ?Period $within = null): self
    {
        $weekly = [];
        foreach ($schedule->rules()->get() as $rule) {
            $weekly[$rule->weekday][] = [$rule->start_minute, $rule->end_minute];
        }

        $query = $schedule->overrides();
        if ($within) {
            // Local dates can sit up to a day either side of the UTC range.
            $query->whereBetween('date', [
                $within->start->subDay()->toDateString(),
                $within->end->addDay()->toDateString(),
            ]);
        }

        $overrides = [];
        $unavailable = [];
        foreach ($query->get() as $override) {
            $date = $override->date->toDateString();
            $overrides[$date] ??= [];

            if ($override->isUnavailable()) {
                $overrides[$date] = [];
                $unavailable[$date] = true;
            } elseif (! isset($unavailable[$date])) {
                $overrides[$date][] = [$override->start_minute, $override->end_minute];
            }
        }

        return new self($schedule->timezone, $weekly, $overrides);
    }

    /**
     * Ranges that apply on a local calendar date ('Y-m-d').
     *
     * @return list<array{0: int, 1: int}>
     */
    public function rangesOn(string $date): array
    {
        if (array_key_exists($date, $this->overrides)) {
            return $this->overrides[$date];
        }

        return $this->weekly[CarbonImmutable::parse($date, 'UTC')->dayOfWeek] ?? [];
    }

    /**
     * The real instant at which a local wall-clock time occurs on a date.
     *
     * Non-existent times (inside a spring-forward gap) resolve to the first
     * valid instant after the gap; ambiguous times (fall-back) resolve to the
     * first occurrence, as PHP does.
     */
    public function instantAt(string $date, int $minute): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date, 'UTC')->addDays(intdiv($minute, 1440));
        $minute %= 1440;

        return CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            sprintf('%s %02d:%02d', $day->toDateString(), intdiv($minute, 60), $minute % 60),
            $this->timezone,
        )->utc();
    }
}
