<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Computes bookable start times. Pure: no database, clock or network access,
 * so every rule can be unit-tested with fixed inputs.
 *
 * A slot [start, start + duration) is offered when:
 *  - it lies entirely inside the host's working hours (adjacent ranges and
 *    ranges that meet at midnight are merged first);
 *  - it starts at least min-notice after "now" and before the end of the
 *    host-local day that is max-days-ahead days from today;
 *  - the slot widened by its buffers overlaps no busy period (buffers may
 *    extend outside working hours; they only guard against other events);
 *  - the host's daily limit for that host-local date is not reached.
 *
 * Start times step from the beginning of each working range in real elapsed
 * time, so they stay correct across DST changes.
 */
final class SlotEngine
{
    /**
     * @param  list<Period>  $busy  Busy periods in UTC (calendar events, existing bookings with their buffers).
     * @param  array<string, int>  $bookingsPerDay  Existing bookings counted against the daily limit, keyed by host-local 'Y-m-d'.
     * @return list<CarbonImmutable> Slot start times in UTC, ascending.
     */
    public function slots(
        SlotRules $rules,
        WeeklyAvailability $availability,
        Period $range,
        CarbonImmutable $now,
        array $busy = [],
        array $bookingsPerDay = [],
    ): array {
        $now = $now->utc();
        $earliest = $now->addMinutes($rules->minNoticeMinutes);
        $latest = $now->setTimezone($availability->timezone)->startOfDay()->addDays($rules->maxDaysAhead + 1)->utc();

        $from = $range->start->max($earliest);
        $until = $range->end->min($latest);

        if ($from >= $until) {
            return [];
        }

        // The hot loop works on Unix timestamps; Carbon is only used for results.
        $fromTs = $from->getTimestamp();
        $untilTs = $until->getTimestamp();
        $duration = $rules->durationMinutes * 60;
        $step = $rules->step() * 60;
        $before = $rules->bufferBeforeMinutes * 60;
        $after = $rules->bufferAfterMinutes * 60;

        // Merged busy periods are disjoint and ascending, and candidate slots are
        // generated in ascending order, so one forward-moving cursor is enough.
        $busy = array_map(fn (Period $p) => [$p->start->getTimestamp(), $p->end->getTimestamp()], $this->merge($busy));
        $busyCount = count($busy);
        $cursor = 0;

        $hostZone = new DateTimeZone($availability->timezone);
        $slots = [];

        foreach ($this->workingWindows($availability, $from, $until) as $window) {
            $windowEnd = $window->end->getTimestamp();
            $start = $window->start->getTimestamp();

            // Jump straight to the first start time on this window's grid that is >= $from.
            if ($start < $fromTs) {
                $start += (int) ceil(($fromTs - $start) / $step) * $step;
            }

            for (; $start + $duration <= $windowEnd && $start < $untilTs; $start += $step) {
                $blockedStart = $start - $before;
                $blockedEnd = $start + $duration + $after;

                while ($cursor < $busyCount && $busy[$cursor][1] <= $blockedStart) {
                    $cursor++;
                }

                if ($cursor < $busyCount && $busy[$cursor][0] < $blockedEnd) {
                    continue;
                }

                $slot = CarbonImmutable::createFromTimestampUTC($start);

                if ($rules->dailyLimit !== null) {
                    $localDate = $slot->setTimezone($hostZone)->toDateString();

                    if (($bookingsPerDay[$localDate] ?? 0) >= $rules->dailyLimit) {
                        continue;
                    }
                }

                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Group UTC slot starts by calendar date in a viewer's time zone.
     *
     * @param  list<CarbonImmutable>  $slots
     * @return array<string, list<CarbonImmutable>> 'Y-m-d' => starts converted to $timezone.
     */
    public static function groupByDate(array $slots, string $timezone): array
    {
        $grouped = [];

        foreach ($slots as $slot) {
            $local = $slot->setTimezone($timezone);
            $grouped[$local->toDateString()][] = $local;
        }

        return $grouped;
    }

    /**
     * Working hours that touch [from, until), as merged UTC periods.
     *
     * @return list<Period>
     */
    private function workingWindows(WeeklyAvailability $availability, CarbonImmutable $from, CarbonImmutable $until): array
    {
        // Walk local calendar dates (as plain dates, so DST never shifts them).
        $date = CarbonImmutable::parse($from->setTimezone($availability->timezone)->toDateString(), 'UTC')->subDay();
        $lastDate = $until->setTimezone($availability->timezone)->toDateString();

        $windows = [];
        while ($date->toDateString() <= $lastDate) {
            $day = $date->toDateString();

            foreach ($availability->rangesOn($day) as [$startMinute, $endMinute]) {
                $start = $availability->instantAt($day, $startMinute);
                $end = $availability->instantAt($day, $endMinute);

                // A range lying entirely in a spring-forward gap collapses to nothing.
                if ($end > $start && $end > $from && $start < $until) {
                    $windows[] = new Period($start, $end);
                }
            }

            $date = $date->addDay();
        }

        return $this->merge($windows);
    }

    /**
     * @param  list<Period>  $periods
     * @return list<Period>
     */
    private function merge(array $periods): array
    {
        usort($periods, fn (Period $a, Period $b) => $a->start <=> $b->start);

        $merged = [];
        foreach ($periods as $period) {
            $last = end($merged);

            if ($last && $period->start <= $last->end) {
                $merged[array_key_last($merged)] = new Period($last->start, $period->end->max($last->end));
            } else {
                $merged[] = $period;
            }
        }

        return $merged;
    }
}
