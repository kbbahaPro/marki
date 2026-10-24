<?php

namespace App\Listeners;

use App\Enums\SyncStatus;
use App\Events\BookingCreated;
use App\Jobs\SyncBookingToCalendar;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates the calendar event right after booking (not queued), so the Meet
 * link exists before confirmation emails go out. A failure never undoes the
 * booking: it is recorded and retried in the background.
 */
class CreateCalendarEvent
{
    public function __construct(private readonly CalendarProvider $calendar) {}

    public function handle(BookingCreated $event): void
    {
        try {
            $this->calendar->createEvent($event->booking);
        } catch (Throwable $e) {
            report($e);

            $event->booking->forceFill([
                'sync_status' => SyncStatus::Failed,
                'sync_error' => Str::limit($e->getMessage(), 1000),
            ])->save();

            try {
                SyncBookingToCalendar::dispatch($event->booking)->delay(now()->addMinute());
            } catch (Throwable $retryError) {
                // With the "sync" queue driver the retry runs inline; its failure must
                // not stop the confirmation emails, which are the next listener.
                report($retryError);
            }
        }
    }
}
