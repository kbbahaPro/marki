<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\SchedulingType;
use App\Enums\SyncStatus;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingOutcomeRecorded;
use App\Events\BookingRescheduled;
use App\Exceptions\BookingNotChangeableException;
use App\Exceptions\CalendarUnavailableException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, cancels and reschedules bookings without double-booking a host.
 *
 * Concurrency strategy: every booking or reschedule runs in a transaction
 * that first locks the users rows of everyone who could host it (SELECT …
 * FOR UPDATE), always in ascending id order so two bookings with
 * overlapping host sets cannot deadlock. Changes involving the same host
 * are therefore serialised; the loser of a race waits for the winner to
 * commit, then re-checks availability and sees the new booking. This also
 * covers overlaps a unique index cannot catch (different start times,
 * different durations, buffers, co-hosts). The unique index on
 * (host_id, active_start_at) remains as a last line of defence for organizers.
 */
class BookingService
{
    /** Round robin fairness looks at assignments in this many recent days. */
    private const FAIRNESS_DAYS = 30;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return array{0: Booking, 1: string} The booking and its raw manage token (only its hash is stored).
     *
     * @throws SlotUnavailableException
     */
    public function book(EventType $eventType, CarbonImmutable $start, GuestDetails $guest, ?CarbonImmutable $now = null): array
    {
        $start = $start->utc();
        $token = Str::random(64);
        $candidates = $this->candidateHostIds($eventType);
        $externalBusy = $this->externalBusy($eventType, $candidates, $start);

        $booking = $this->underHostLock($candidates, function () use ($eventType, $start, $guest, $now, $externalBusy, $token, $candidates) {
            [$organizerId, $coHostIds] = $this->assignHosts($eventType, $start, $now, $externalBusy, $candidates);

            $this->pauseForConcurrencyTest();

            $booking = Booking::create($this->timing($eventType, $start) + [
                'event_type_id' => $eventType->id,
                'host_id' => $organizerId,
                'guest_name' => $guest->name,
                'guest_email' => $guest->email,
                'guest_phone' => $guest->phone,
                'guest_timezone' => $guest->timezone,
                'guest_notes' => $guest->notes,
                'status' => BookingStatus::Confirmed,
                'manage_token_hash' => hash('sha256', $token),
                'manage_token' => $token,
                'location_type' => $eventType->location_type,
                'location_value' => $eventType->location_value,
            ]);

            $booking->hosts()->attach($coHostIds);
            $booking->answers()->createMany($guest->answers);

            return $booking;
        });

        BookingCreated::dispatch($booking, $token);

        return [$booking, $token];
    }

    /**
     * Move a booking to a new start time. The old row is kept (status
     * "rescheduled") and a new confirmed row with a new manage token replaces it.
     * Team bookings keep their hosts when they are free at the new time.
     *
     * @param  'guest'|'host'  $by
     * @param  User|null  $actor  The host or team admin who moved it, when $by is 'host'.
     * @return array{0: Booking, 1: string} The new booking and its raw manage token.
     *
     * @throws SlotUnavailableException
     * @throws BookingNotChangeableException
     */
    public function reschedule(Booking $previous, CarbonImmutable $start, ?CarbonImmutable $now = null, string $by = 'guest', ?User $actor = null): array
    {
        $start = $start->utc();
        $eventType = $previous->eventType;
        $token = Str::random(64);
        $candidates = $this->candidateHostIds($eventType);
        $externalBusy = $this->externalBusy($eventType, $candidates, $start);
        // The previous hosts are locked too: their time is being freed.
        $locked = array_values(array_unique([...$candidates, ...$previous->hosts()->pluck('users.id')->all()]));

        $booking = $this->underHostLock($locked, function () use ($previous, $eventType, $start, $now, $externalBusy, $token, $candidates) {
            $previous = Booking::query()->whereKey($previous->id)->lockForUpdate()->first();

            if (! $previous->isChangeable()) {
                throw new BookingNotChangeableException;
            }

            [$organizerId, $coHostIds] = $this->assignHosts($eventType, $start, $now, $externalBusy, $candidates, $previous);

            // Retire the old row first so the unique (host, start) index allows the same start time.
            $previous->forceFill(['status' => BookingStatus::Rescheduled])->save();

            $booking = Booking::create($this->timing($eventType, $start) + [
                'event_type_id' => $previous->event_type_id,
                'host_id' => $organizerId,
                'guest_name' => $previous->guest_name,
                'guest_email' => $previous->guest_email,
                'guest_phone' => $previous->guest_phone,
                'guest_timezone' => $previous->guest_timezone,
                'guest_notes' => $previous->guest_notes,
                'status' => BookingStatus::Confirmed,
                'manage_token_hash' => hash('sha256', $token),
                'manage_token' => $token,
                'location_type' => $previous->location_type,
                'location_value' => $previous->location_value,
                'rescheduled_from_id' => $previous->id,
                'ical_uid' => $previous->ical_uid ?? $previous->uid,
                'ical_sequence' => $previous->ical_sequence + 1,
            ]);

            $booking->hosts()->attach($coHostIds);

            // A new organizer means a new calendar event, so the old Meet link would not carry over.
            $booking->forceFill([
                'meeting_url' => $organizerId === $previous->host_id ? $previous->meeting_url : null,
                'sync_status' => SyncStatus::Pending,
            ])->save();

            $booking->answers()->createMany($previous->answers()->get()
                ->map(fn ($a) => $a->only(['question_id', 'label', 'value', 'position']))->all());

            return $booking;
        });

        BookingRescheduled::dispatch($previous->fresh(), $booking, $token, $by, $actor?->id);

        return [$booking, $token];
    }

    /**
     * @param  'guest'|'host'  $by
     * @param  User|null  $actor  The host or team admin who cancelled, when $by is 'host'.
     *
     * @throws BookingNotChangeableException
     */
    public function cancel(Booking $booking, string $by, ?string $reason = null, ?User $actor = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $by, $reason, $actor) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if (! $booking->isChangeable()) {
                throw new BookingNotChangeableException;
            }

            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_by' => $by,
                'cancelled_by_user_id' => $by === 'host' ? $actor?->id : null,
                'cancel_reason' => filled($reason) ? $reason : null,
                'cancelled_at' => now(),
                'ical_sequence' => $booking->ical_sequence + 1,
            ])->save();

            return $booking;
        });

        BookingCancelled::dispatch($booking);

        return $booking;
    }

    /**
     * Record how a meeting went: completed or no-show. Allowed once it has
     * started, and switchable between the two to correct a mistake.
     *
     * @param  bool  $onlyIfConfirmed  Leave bookings a host already marked alone (automatic completion).
     * @return bool Whether the status changed.
     *
     * @throws BookingNotChangeableException
     */
    public function recordOutcome(Booking $booking, BookingStatus $outcome, bool $onlyIfConfirmed = false): bool
    {
        if (! in_array($outcome, [BookingStatus::Completed, BookingStatus::NoShow], true)) {
            throw new InvalidArgumentException("Not an outcome: {$outcome->value}");
        }

        $changed = DB::transaction(function () use ($booking, $outcome, $onlyIfConfirmed) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if ($onlyIfConfirmed && $booking->status !== BookingStatus::Confirmed) {
                return null;
            }

            if (! $booking->canRecordOutcome()) {
                throw new BookingNotChangeableException;
            }

            if ($booking->status === $outcome) {
                return null;
            }

            $booking->forceFill(['status' => $outcome])->save();

            return $booking;
        });

        if ($changed === null) {
            return false;
        }

        BookingOutcomeRecorded::dispatch($changed);

        return true;
    }

    /**
     * Everyone who could host a booking of this event type.
     *
     * @return list<int>
     */
    private function candidateHostIds(EventType $eventType): array
    {
        return $eventType->isTeamEvent()
            ? $eventType->hosts()->pluck('users.id')->sort()->values()->all()
            : [$eventType->user_id];
    }

    /**
     * Check availability (under the lock) and choose the organizer and co-hosts.
     *
     * @param  list<Period>|array<int, list<Period>|null>  $externalBusy
     * @param  list<int>  $locked  Hosts whose rows this transaction holds.
     * @return array{0: int, 1: list<int>} Organizer id and co-host ids.
     *
     * @throws SlotUnavailableException
     */
    private function assignHosts(EventType $eventType, CarbonImmutable $start, ?CarbonImmutable $now, array $externalBusy, array $locked, ?Booking $previous = null): array
    {
        if (! $eventType->isTeamEvent()) {
            if (! $this->availability->isAvailable($eventType, $start, $now, $externalBusy, ignoring: $previous)) {
                throw new SlotUnavailableException;
            }

            return [$previous->host_id ?? $eventType->user_id, []];
        }

        $slots = $this->availability->teamSlots($eventType, new Period($start, $start->addSecond()), $now, $externalBusy, $previous);
        $freeRotating = $slots->rotatingHostsAt($start);

        if ($freeRotating === null) {
            throw new SlotUnavailableException;
        }

        $hosts = $eventType->hosts()->with('googleConnection')->get();

        // Hosts added after the lock was taken are not protected by it; let the guest retry.
        if (array_diff($hosts->modelKeys(), $locked) !== []) {
            throw new SlotUnavailableException;
        }

        if ($eventType->scheduling_type === SchedulingType::Collective) {
            $organizer = $this->organizerAmong($hosts, $previous);

            return [$organizer->id, $hosts->except($organizer->id)->modelKeys()];
        }

        // Round robin: keep the previous host when they are free, as agreed for reschedules.
        $assigned = $previous && in_array($previous->host_id, $freeRotating, true)
            ? $previous->host_id
            : $this->leastBusy($eventType, $hosts->only($freeRotating))->id;

        $fixed = $hosts->filter(fn (User $host) => $host->pivot->is_fixed)->modelKeys();

        return [$assigned, array_values(array_diff($fixed, [$assigned]))];
    }

    /**
     * The collective host whose calendar holds the event: the previous organizer
     * when rescheduling, else someone with Google connected (for the Meet link).
     *
     * @param  Collection<int, User>  $hosts
     */
    private function organizerAmong(Collection $hosts, ?Booking $previous): User
    {
        return $hosts->firstWhere('id', $previous?->host_id)
            ?? $hosts->sortBy([
                fn (User $a, User $b) => (int) (bool) $b->googleConnection?->isActive() <=> (int) (bool) $a->googleConnection?->isActive(),
                fn (User $a, User $b) => $a->id <=> $b->id,
            ])->first();
    }

    /**
     * Round robin choice among free rotating hosts: highest priority first, then
     * the fewest bookings of this event type in the last 30 days relative to
     * weight, then whoever was assigned least recently, then the lowest id.
     *
     * @param  Collection<int, User>  $hosts
     */
    private function leastBusy(EventType $eventType, Collection $hosts): User
    {
        $recent = Booking::query()
            ->confirmed()
            ->where('event_type_id', $eventType->id)
            ->whereIn('host_id', $hosts->modelKeys())
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::FAIRNESS_DAYS))
            ->groupBy('host_id')
            ->selectRaw('host_id, count(*) as bookings, max(created_at) as last_assigned_at')
            ->get()
            ->keyBy('host_id');

        return $hosts->sortBy([
            fn (User $a, User $b) => $b->pivot->priority <=> $a->pivot->priority,
            fn (User $a, User $b) => ($recent[$a->id]->bookings ?? 0) / $a->pivot->weight <=> ($recent[$b->id]->bookings ?? 0) / $b->pivot->weight,
            fn (User $a, User $b) => (string) ($recent[$a->id]->last_assigned_at ?? '') <=> (string) ($recent[$b->id]->last_assigned_at ?? ''),
            fn (User $a, User $b) => $a->id <=> $b->id,
        ])->first();
    }

    /**
     * Run $callback in a transaction holding the hosts' row locks.
     *
     * @template T
     *
     * @param  list<int>  $hostIds
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws SlotUnavailableException
     */
    private function underHostLock(array $hostIds, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($hostIds, $callback) {
                // This must be the transaction's first statement. Besides serialising
                // changes per host, it means MySQL's REPEATABLE READ snapshot is only
                // taken by the reads that follow, after every earlier booking has committed.
                // Ascending id order: transactions locking overlapping host sets cannot deadlock.
                User::query()->whereKey($hostIds)->orderBy('id')->lockForUpdate()->get(['id']);

                return $callback();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new SlotUnavailableException;
        }
    }

    /**
     * Calendar APIs are called before taking the lock, so the lock is only held for milliseconds.
     *
     * @param  list<int>  $hostIds
     * @return list<Period>|array<int, list<Period>|null> A personal host's busy times, or busy times per team host
     *                                                    (null when that host's calendar cannot be read).
     *
     * @throws SlotUnavailableException
     */
    private function externalBusy(EventType $eventType, array $hostIds, CarbonImmutable $start): array
    {
        $end = $start->addMinutes($eventType->duration_minutes);
        $range = new Period($start->subDay(), $end->addDay());

        if (! $eventType->isTeamEvent()) {
            try {
                return $this->availability->externalBusy($eventType->user, $range);
            } catch (CalendarUnavailableException) {
                throw new SlotUnavailableException;
            }
        }

        $busy = [];

        foreach (User::query()->whereKey($hostIds)->get() as $host) {
            try {
                $busy[$host->id] = $this->availability->externalBusy($host, $range);
            } catch (CalendarUnavailableException) {
                $busy[$host->id] = null;
            }
        }

        return $busy;
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    private function timing(EventType $eventType, CarbonImmutable $start): array
    {
        $end = $start->addMinutes($eventType->duration_minutes);

        return [
            'start_at' => $start,
            'end_at' => $end,
            'blocked_start_at' => $start->subMinutes($eventType->buffer_before_minutes),
            'blocked_end_at' => $end->addMinutes($eventType->buffer_after_minutes),
        ];
    }

    /**
     * Widens the window between the availability check and the insert so the
     * concurrency test can prove the lock (not luck) prevents double-booking.
     */
    private function pauseForConcurrencyTest(): void
    {
        $milliseconds = (int) config('booking.testing.lock_pause_ms');

        if ($milliseconds > 0 && app()->environment('testing')) {
            usleep($milliseconds * 1000);
        }
    }
}
