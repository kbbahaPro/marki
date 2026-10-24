<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retries removing a cancelled booking's calendar event.
 */
class DeleteCalendarEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Booking $booking) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [300, 900, 3600, 21600];
    }

    public function handle(CalendarProvider $calendar): void
    {
        $calendar->deleteEvent($this->booking->fresh());
    }

    public function failed(Throwable $e): void
    {
        $this->booking->forceFill(['sync_error' => Str::limit($e->getMessage(), 1000)])->save();
    }
}
