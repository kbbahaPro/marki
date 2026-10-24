<?php

namespace App\Services\Scheduling;

use App\Exceptions\CalendarUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Gathers a host's working hours, bookings and external busy times from
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
     * @param  list<Period>|null  $externalBusy  Pre-fetched calendar busy times; fetched when null.
     * @param  Booking|null  $ignoring  A booking being rescheduled: its own time does not block the move.
     * @return list<CarbonImmutable>
     */
    public function slots(EventType $eventType, Period $range, ?CarbonImmutable $now = null, ?array $externalBusy = null, ?Booking $ignoring = null): array
    {
        $schedule = $eventType->effectiveSchedule();

        if (! $eventType->is_active || $eventType->trashed() || $schedule === null) {
            return [];
        }

        // Bookings and overrides just outside the range can still affect edge slots.
        $lookup = new Period($range->start->subDay(), $range->end->addDay());

        try {
            $externalBusy ??= $this->externalBusy($eventType->user, $lookup);
        } catch (CalendarUnavailableException) {
            return [];
        }

        $bookings = Booking::query()
            ->blockingHost($eventType->user_id, $lookup)
            ->when($ignoring, fn ($q) => $q->whereKeyNot($ignoring->id))
            ->get(['start_at', 'end_at', 'blocked_start_at', 'blocked_end_at']);

        if ($ignoring) {
            // The booking's own event also appears in the host's calendar busy times.
            $externalBusy = $this->subtract($externalBusy, new Period($ignoring->start_at, $ignoring->end_at));
        }

        return $this->engine->slots(
            rules: SlotRules::fromEventType($eventType),
            availability: WeeklyAvailability::fromSchedule($schedule, $lookup),
            range: $range,
            now: $now ?? CarbonImmutable::now(),
            busy: [...$externalBusy, ...$bookings->map(fn (Booking $b) => new Period($b->start_at, $b->end_at))],
            bookingsPerDay: $this->bookingsPerDay($eventType, $lookup, $schedule->timezone, $ignoring),
            bufferZones: $bookings->map(fn (Booking $b) => new Period($b->blocked_start_at, $b->blocked_end_at))->all(),
        );
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
     * Confirmed bookings of this event type per host-local date, for the daily limit.
     *
     * @return array<string, int>
     */
    private function bookingsPerDay(EventType $eventType, Period $lookup, string $timezone, ?Booking $ignoring): array
    {
        if ($eventType->daily_limit === null) {
            return [];
        }

        // Widen by a day each side: local dates straddle UTC dates.
        return Booking::query()
            ->confirmed()
            ->where('event_type_id', $eventType->id)
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
