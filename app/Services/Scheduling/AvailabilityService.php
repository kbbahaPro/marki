<?php

namespace App\Services\Scheduling;

use App\Enums\SchedulingType;
use App\Exceptions\CalendarUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Gathers hosts' working hours, bookings and external busy times from
 * storage and asks the SlotEngine which start times are bookable.
 */
class AvailabilityService
{
    /**
     * @param  iterable<BusyTimeSource>  $sources
     */
    public function __construct(
        private readonly SlotEngine $engine,
        private readonly iterable $sources = [],
    ) {}

    /**
     * Busy times from the host's connected calendars.
     *
     * @return list<Period>
     *
     * @throws CalendarUnavailableException
     */
    public function externalBusy(User $host, Period $range): array
    {
        $busy = [];

        foreach ($this->sources as $source) {
            array_push($busy, ...$source->busyPeriods($host, $range));
        }

        return $busy;
    }

    /**
     * Bookable start times (UTC) for an event type inside $range.
     *
     * @param  list<Period>|null  $externalBusy  Pre-fetched calendar busy times of a personal event type's host; fetched when null.
     * @param  Booking|null  $ignoring  A booking being rescheduled: its own time does not block the move.
     * @return list<CarbonImmutable>
     */
    public function slots(EventType $eventType, Period $range, ?CarbonImmutable $now = null, ?array $externalBusy = null, ?Booking $ignoring = null): array
    {
        if ($eventType->isTeamEvent()) {
            return $this->teamSlots($eventType, $range, $now, ignoring: $ignoring)->starts();
        }

        $schedule = $eventType->effectiveSchedule();

        if (! $eventType->is_active || $eventType->trashed() || $schedule === null) {
            return [];
        }

        return $this->hostSlots(
            $eventType,
            $eventType->user,
            WeeklyAvailability::fromSchedule($schedule, $this->lookup($range)),
            $range,
            $now ?? CarbonImmutable::now(),
            $externalBusy,
            $ignoring,
        ) ?? [];
    }

    /**
     * Bookable start times of a team event type, checking every host against
     * their own working hours, calendars, bookings, buffers and daily limit.
     *
     * Collective: every host must be free. Round robin: every fixed host and
     * at least one rotating host must be free; the free rotating hosts come
     * back with each start so the booking can be assigned among them.
     *
     * @param  array<int, list<Period>>  $externalBusy  Pre-fetched calendar busy times keyed by host id; missing hosts are fetched.
     */
    public function teamSlots(EventType $eventType, Period $range, ?CarbonImmutable $now = null, array $externalBusy = [], ?Booking $ignoring = null): TeamSlots
    {
        if (! $eventType->is_active || $eventType->trashed()) {
            return new TeamSlots;
        }

        $now ??= CarbonImmutable::now();
        $lookup = $this->lookup($range);
        $collective = $eventType->scheduling_type === SchedulingType::Collective;
        $hosts = $eventType->hosts()->with('defaultSchedule')->get();

        $availabilities = $hosts
            ->filter(fn (User $host) => $host->defaultSchedule !== null)
            ->mapWithKeys(fn (User $host) => [$host->id => WeeklyAvailability::fromSchedule($host->defaultSchedule, $lookup)]);

        $grid = $this->engine->anchoredGrid($availabilities->values()->all(), $range, SlotRules::fromEventType($eventType)->step());

        $required = [];
        $rotating = [];

        foreach ($hosts as $host) {
            // A host who cannot be checked (no schedule, calendar unreachable) counts as busy.
            $starts = $availabilities->has($host->id)
                ? $this->hostSlots($eventType, $host, $availabilities[$host->id], $range, $now, $externalBusy[$host->id] ?? null, $ignoring, $grid)
                : null;
            $free = array_fill_keys(array_map(fn (CarbonImmutable $start) => $start->getTimestamp(), $starts ?? []), true);

            if ($collective || $host->pivot->is_fixed) {
                $required[] = $free;
            } else {
                $rotating[$host->id] = $free;
            }
        }

        if ($hosts->isEmpty() || (! $collective && $rotating === [])) {
            return new TeamSlots;
        }

        $result = [];

        foreach ($grid as $ts) {
            foreach ($required as $free) {
                if (! isset($free[$ts])) {
                    continue 2;
                }
            }

            $available = array_keys(array_filter($rotating, fn (array $free) => isset($free[$ts])));

            if ($collective || $available !== []) {
                $result[$ts] = $available;
            }
        }

        return new TeamSlots($result);
    }

    /**
     * Whether one exact start time is bookable right now.
     *
     * @param  list<Period>|null  $externalBusy
     */
    public function isAvailable(EventType $eventType, CarbonImmutable $start, ?CarbonImmutable $now = null, ?array $externalBusy = null, ?Booking $ignoring = null): bool
    {
        $start = $start->utc();
        $slots = $this->slots($eventType, new Period($start, $start->addSecond()), $now, $externalBusy, $ignoring);

        return count($slots) === 1 && $slots[0]->equalTo($start);
    }

    /**
     * One host's bookable start times, or null when their calendar cannot be read.
     *
     * @param  list<Period>|null  $externalBusy  Fetched when null.
     * @param  list<int>|null  $grid  A team's shared start times; personal event types step from the working hours.
     * @return list<CarbonImmutable>|null
     */
    private function hostSlots(EventType $eventType, User $host, WeeklyAvailability $availability, Period $range, CarbonImmutable $now, ?array $externalBusy, ?Booking $ignoring, ?array $grid = null): ?array
    {
        // Bookings and overrides just outside the range can still affect edge slots.
        $lookup = $this->lookup($range);

        try {
            $externalBusy ??= $this->externalBusy($host, $lookup);
        } catch (CalendarUnavailableException) {
            return null;
        }

        $bookings = Booking::query()
            ->blockingHost($host->id, $lookup)
            ->when($ignoring, fn ($q) => $q->whereKeyNot($ignoring->id))
            ->get(['start_at', 'end_at', 'blocked_start_at', 'blocked_end_at']);

        if ($ignoring && $ignoring->hosts->contains($host)) {
            // The booking's own event also appears in its hosts' calendar busy times.
            $externalBusy = $this->subtract($externalBusy, new Period($ignoring->start_at, $ignoring->end_at));
        }

        return $this->engine->slots(
            rules: SlotRules::fromEventType($eventType),
            availability: $availability,
            range: $range,
            now: $now,
            busy: [...$externalBusy, ...$bookings->map(fn (Booking $b) => new Period($b->start_at, $b->end_at))],
            bookingsPerDay: $this->bookingsPerDay($eventType, $host, $lookup, $availability->timezone, $ignoring),
            bufferZones: $bookings->map(fn (Booking $b) => new Period($b->blocked_start_at, $b->blocked_end_at))->all(),
            candidates: $grid,
        );
    }

    private function lookup(Period $range): Period
    {
        return new Period($range->start->subDay(), $range->end->addDay());
    }

    /**
     * Confirmed bookings of this event type the host attends, per host-local date, for the daily limit.
     *
     * @return array<string, int>
     */
    private function bookingsPerDay(EventType $eventType, User $host, Period $lookup, string $timezone, ?Booking $ignoring): array
    {
        if ($eventType->daily_limit === null) {
            return [];
        }

        // Widen by a day each side: local dates straddle UTC dates.
        return Booking::query()
            ->confirmed()
            ->where('event_type_id', $eventType->id)
            ->attendedBy($host->id)
            ->when($ignoring, fn ($q) => $q->whereKeyNot($ignoring->id))
            ->whereBetween('start_at', [$lookup->start->subDay(), $lookup->end->addDay()])
            ->pluck('start_at')
            ->countBy(fn (CarbonImmutable $start) => $start->setTimezone($timezone)->toDateString())
            ->all();
    }

    /**
     * Remove $hole from each period, splitting periods that contain it.
     *
     * @param  list<Period>  $periods
     * @return list<Period>
     */
    private function subtract(array $periods, Period $hole): array
    {
        $result = [];

        foreach ($periods as $period) {
            if (! $period->overlaps($hole)) {
                $result[] = $period;

                continue;
            }

            if ($period->start < $hole->start) {
                $result[] = new Period($period->start, $hole->start);
            }

            if ($period->end > $hole->end) {
                $result[] = new Period($hole->end, $period->end);
            }
        }

        return $result;
    }
}
