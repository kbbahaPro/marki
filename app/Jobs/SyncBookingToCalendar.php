<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Enums\SyncStatus;
use App\Mail\MeetingLinkReady;
use App\Models\Booking;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retries creating a booking's calendar event after the immediate attempt failed.
 */
class SyncBookingToCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Booking $booking) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [300, 900, 3600, 21600];
    }

    public function handle(CalendarProvider $calendar): void
    {
        $booking = $this->booking->fresh();

        if ($booking === null || $booking->status !== BookingStatus::Confirmed || $booking->sync_status === SyncStatus::Synced) {
            return;
        }

        $calendar->createEvent($booking);

        // The guest's confirmation went out without a Meet link; send it now.
        if ($booking->location_type === LocationType::GoogleMeet && $booking->meeting_url) {
            Mail::to($booking->guest_email, $booking->guest_name)->send(new MeetingLinkReady($booking));
        }
    }

    public function failed(Throwable $e): void
    {
        $this->booking->forceFill([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
    }
}
