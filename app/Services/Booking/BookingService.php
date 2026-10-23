<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Events\BookingCreated;
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
 * Creates bookings without double-booking a host.
 *
 * Concurrency strategy: every booking for a host runs in a transaction that
 * first locks that host's users row (SELECT … FOR UPDATE). Bookings for the
 * same host are therefore serialised; the loser of a race waits for the
 * winner to commit, then re-checks availability and sees the new booking.
 * This also covers overlaps a unique index cannot catch (different start
 * times, different durations, buffers). The unique index on
 * (host_id, active_start_at) remains as a last line of defence.
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
        $end = $start->addMinutes($eventType->duration_minutes);
        $token = Str::random(64);

        // Calendar APIs are called before taking the lock, so the lock is only held for milliseconds.
        try {
            $externalBusy = $this->availability->externalBusy(
                $eventType->user,
                new Period($start->subDay(), $end->addDay()),
            );
        } catch (CalendarUnavailableException) {
            throw new SlotUnavailableException;
        }

        try {
            $booking = DB::transaction(function () use ($eventType, $start, $end, $guest, $now, $externalBusy, $token) {
                // This must be the transaction's first statement. Besides serialising
                // bookings per host, it means MySQL's REPEATABLE READ snapshot is only
                // taken by the reads below, after every earlier booking has committed.
                User::query()->whereKey($eventType->user_id)->lockForUpdate()->first();

                if (! $this->availability->isAvailable($eventType, $start, $now, $externalBusy)) {
                    throw new SlotUnavailableException;
                }

                $this->pauseForConcurrencyTest();

                $booking = Booking::create([
                    'event_type_id' => $eventType->id,
                    'host_id' => $eventType->user_id,
                    'start_at' => $start,
                    'end_at' => $end,
                    'blocked_start_at' => $start->subMinutes($eventType->buffer_before_minutes),
                    'blocked_end_at' => $end->addMinutes($eventType->buffer_after_minutes),
                    'guest_name' => $guest->name,
                    'guest_email' => $guest->email,
                    'guest_phone' => $guest->phone,
                    'guest_timezone' => $guest->timezone,
                    'guest_notes' => $guest->notes,
                    'status' => BookingStatus::Confirmed,
                    'manage_token_hash' => hash('sha256', $token),
                    'location_type' => $eventType->location_type,
                    'location_value' => $eventType->location_value,
                ]);

                $booking->answers()->createMany($guest->answers);

                return $booking;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SlotUnavailableException;
        }

        BookingCreated::dispatch($booking, $token);

        return [$booking, $token];
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
