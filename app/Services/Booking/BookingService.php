<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\SyncStatus;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, cancels and reschedules bookings without double-booking a host.
 *
 * Concurrency strategy: every booking or reschedule for a host runs in a
 * transaction that first locks that host's users row (SELECT … FOR UPDATE).
 * Changes for the same host are therefore serialised; the loser of a race
 * waits for the winner to commit, then re-checks availability and sees the
 * new booking. This also covers overlaps a unique index cannot catch
 * (different start times, different durations, buffers). The unique index
 * on (host_id, active_start_at) remains as a last line of defence.
 */
class BookingService
{
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
        $externalBusy = $this->externalBusy($eventType, $start);

        $booking = $this->underHostLock($eventType, function () use ($eventType, $start, $guest, $now, $externalBusy, $token) {
            if (! $this->availability->isAvailable($eventType, $start, $now, $externalBusy)) {
                throw new SlotUnavailableException;
            }

            $this->pauseForConcurrencyTest();

            $booking = Booking::create($this->timing($eventType, $start) + [
                'event_type_id' => $eventType->id,
                'host_id' => $eventType->user_id,
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

            $booking->answers()->createMany($guest->answers);

            return $booking;
        });

        BookingCreated::dispatch($booking, $token);

        return [$booking, $token];
    }

    /**
     * Move a booking to a new start time. The old row is kept (status
     * "rescheduled") and a new confirmed row with a new manage token replaces it.
     *
     * @return array{0: Booking, 1: string} The new booking and its raw manage token.
     *
     * @throws SlotUnavailableException
     * @throws BookingNotChangeableException
     */
    public function reschedule(Booking $previous, CarbonImmutable $start, ?CarbonImmutable $now = null): array
    {
        $start = $start->utc();
        $eventType = $previous->eventType;
        $token = Str::random(64);
        $externalBusy = $this->externalBusy($eventType, $start);

        $booking = $this->underHostLock($eventType, function () use ($previous, $eventType, $start, $now, $externalBusy, $token) {
            $previous = Booking::query()->whereKey($previous->id)->lockForUpdate()->first();

            if (! $previous->isChangeable()) {
                throw new BookingNotChangeableException;
            }

            if (! $this->availability->isAvailable($eventType, $start, $now, $externalBusy, ignoring: $previous)) {
                throw new SlotUnavailableException;
            }

            // Retire the old row first so the unique (host, start) index allows the same start time.
            $previous->forceFill(['status' => BookingStatus::Rescheduled])->save();

            $booking = Booking::create($this->timing($eventType, $start) + [
                'event_type_id' => $previous->event_type_id,
                'host_id' => $previous->host_id,
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

            $booking->forceFill(['meeting_url' => $previous->meeting_url, 'sync_status' => SyncStatus::Pending])->save();

            $booking->answers()->createMany($previous->answers()->get()
                ->map(fn ($a) => $a->only(['question_id', 'label', 'value', 'position']))->all());

            return $booking;
        });

        BookingRescheduled::dispatch($previous->fresh(), $booking, $token);

        return [$booking, $token];
    }

    /**
     * @param  'guest'|'host'  $by
     *
     * @throws BookingNotChangeableException
     */
    public function cancel(Booking $booking, string $by, ?string $reason = null): Booking
    {
        $booking = DB::transaction(function () use ($booking, $by, $reason) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if (! $booking->isChangeable()) {
                throw new BookingNotChangeableException;
            }

            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_by' => $by,
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
     * Run $callback in a transaction holding the host's row lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws SlotUnavailableException
     */
    private function underHostLock(EventType $eventType, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($eventType, $callback) {
                // This must be the transaction's first statement. Besides serialising
                // changes per host, it means MySQL's REPEATABLE READ snapshot is only
                // taken by the reads that follow, after every earlier booking has committed.
                User::query()->whereKey($eventType->user_id)->lockForUpdate()->first();

                return $callback();
            });
        } catch (UniqueConstraintViolationException) {
            throw new SlotUnavailableException;
        }
    }

    /**
     * Calendar APIs are called before taking the lock, so the lock is only held for milliseconds.
     *
     * @return list<Period>
     *
     * @throws SlotUnavailableException
     */
    private function externalBusy(EventType $eventType, CarbonImmutable $start): array
    {
        $end = $start->addMinutes($eventType->duration_minutes);

        try {
            return $this->availability->externalBusy($eventType->user, new Period($start->subDay(), $end->addDay()));
        } catch (CalendarUnavailableException) {
            throw new SlotUnavailableException;
        }
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
