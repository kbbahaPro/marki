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
 *  - the slot itself overlaps no buffer zone of an existing booking. Buffer
 *    zones may overlap each other, so the gap between two meetings is the
 *    larger of the two buffers, not their sum;
 *  - the host's daily limit for that host-local date is not reached.
 *
 * Start times step from the beginning of each working range in real elapsed
 * time, so they stay correct across DST changes. Team event types instead
 * test every host against one shared grid (anchoredGrid()).
 */
final class SlotEngine
{
    /**
     * @param  list<Period>  $busy  Times the host is actually busy (calendar events, booked meetings), in UTC.
     * @param  array<string, int>  $bookingsPerDay  Existing bookings counted against the daily limit, keyed by host-local 'Y-m-d'.
     * @param  list<Period>  $bufferZones  Existing bookings widened by their own buffers, in UTC.
     * @param  list<int>|null  $candidates  Ascending UTC timestamps to test instead of stepping from each
     *                                      working range (a team's shared grid, see anchoredGrid()).
     * @return list<CarbonImmutable> Slot start times in UTC, ascending.
     */
    public function slots(
        SlotRules $rules,
        WeeklyAvailability $availability,
        Period $range,
        CarbonImmutable $now,
        array $busy = [],
        array $bookingsPerDay = [],
        array $bufferZones = [],
        ?array $candidates = null,
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
        $busy = $this->toTimestamps($busy);
        $busyCount = count($busy);
        $cursor = 0;

        $zones = $this->toTimestamps($bufferZones);
        $zoneCount = count($zones);
        $zoneCursor = 0;

        $hostZone = new DateTimeZone($availability->timezone);
        $slots = [];

        foreach ($this->workingWindows($availability, $from, $until) as $window) {
            $windowEnd = $window->end->getTimestamp();

            foreach ($this->startsIn($window->start->getTimestamp(), $windowEnd - $duration, $fromTs, $untilTs, $step, $candidates) as $start) {
                $blockedStart = $start - $before;
                $blockedEnd = $start + $duration + $after;

                while ($cursor < $busyCount && $busy[$cursor][1] <= $blockedStart) {
                    $cursor++;
                }

                if ($cursor < $busyCount && $busy[$cursor][0] < $blockedEnd) {
                    continue;
                }

                while ($zoneCursor < $zoneCount && $zones[$zoneCursor][1] <= $start) {
                    $zoneCursor++;
                }

                if ($zoneCursor < $zoneCount && $zones[$zoneCursor][0] < $start + $duration) {
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
     * One start-time grid shared by several hosts, as ascending UTC timestamps in $range.
     *
     * Each host's own grid steps from the start of their working ranges, so a
     * host starting at 9:15 and one starting at 9:00 would never share a slot.
     * Here the hosts' working windows are merged, and each merged window's grid
     * is anchored to the top of the hour (in the time zone of the host whose
     * window starts it): :00/:30 for a 30-minute step, :00/:15/:30/:45 for 15.
     *
     * @param  list<WeeklyAvailability>  $availabilities
     * @return list<int>
     */
    public function anchoredGrid(array $availabilities, Period $range, int $stepMinutes): array
    {
        $windows = [];

        foreach ($availabilities as $availability) {
            foreach ($this->workingWindows($availability, $range->start, $range->end) as $window) {
                $local = $window->start->setTimezone($availability->timezone);
                $start = $window->start->getTimestamp();
                $windows[] = [$start, $window->end->getTimestamp(), $start - $local->minute * 60 - $local->second];
            }
        }

        usort($windows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        // Merge overlapping or touching windows; a merged window keeps its first anchor.
        $merged = [];
        foreach ($windows as $window) {
            $last = array_key_last($merged);

            if ($last !== null && $window[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $window[1]);
            } else {
                $merged[] = $window;
            }
        }

        $step = $stepMinutes * 60;
        $fromTs = $range->start->getTimestamp();
        $untilTs = $range->end->getTimestamp();
        $grid = [];

        foreach ($merged as [$start, $end, $anchor]) {
            $first = max($start, $fromTs);
            $t = $anchor + (int) ceil(($first - $anchor) / $step) * $step;

            for (; $t < $end && $t < $untilTs; $t += $step) {
                $grid[] = $t;
            }
        }

        return $grid;
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
     * Candidate start timestamps in one working window, ascending: from $from
     * (inclusive) to the last start that still fits ($lastStart, inclusive) and
     * before $until. Steps from the window start unless a shared grid is given.
     *
     * @param  list<int>|null  $candidates
     * @return iterable<int>
     */
    private function startsIn(int $windowStart, int $lastStart, int $from, int $until, int $step, ?array $candidates): iterable
    {
        $first = max($windowStart, $from);

        if ($candidates === null) {
            // Jump straight to the first start time on this window's grid that is >= $from.
            for ($start = $windowStart + (int) ceil(($first - $windowStart) / $step) * $step; $start <= $lastStart && $start < $until; $start += $step) {
                yield $start;
            }

            return;
        }

        // Binary search for the first candidate >= $first.
        [$low, $high] = [0, count($candidates)];
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($candidates[$mid] < $first) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        for ($i = $low; $i < count($candidates) && $candidates[$i] <= $lastStart && $candidates[$i] < $until; $i++) {
            yield $candidates[$i];
        }
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
     * Merge periods and return them as ascending [startTs, endTs] pairs.
     *
     * @param  list<Period>  $periods
     * @return list<array{0: int, 1: int}>
     */
    private function toTimestamps(array $periods): array
    {
        return array_map(fn (Period $p) => [$p->start->getTimestamp(), $p->end->getTimestamp()], $this->merge($periods));
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
