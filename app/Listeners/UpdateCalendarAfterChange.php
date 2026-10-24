<?php

namespace App\Listeners;

use App\Enums\SyncStatus;
use App\Events\BookingCancelled;
use App\Events\BookingRescheduled;
use App\Jobs\DeleteCalendarEvent;
use App\Jobs\MoveCalendarEvent;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps the host's calendar in step with cancellations and reschedules.
 * Runs inline (so a moved event keeps its Meet link in the emails that
 * follow); failures are recorded and retried in the background.
 */
class UpdateCalendarAfterChange
{
    public function __construct(private readonly CalendarProvider $calendar) {}

    public function handle(BookingCancelled|BookingRescheduled $event): void
    {
        try {
            if ($event instanceof BookingCancelled) {
                $this->calendar->deleteEvent($event->booking);
            } else {
                $this->calendar->moveEvent($event->previous, $event->booking);
            }
        } catch (Throwable $e) {
            report($e);

            if ($event instanceof BookingRescheduled) {
                $event->booking->forceFill([
                    'sync_status' => SyncStatus::Failed,
                    'sync_error' => Str::limit($e->getMessage(), 1000),
                ])->save();
            }

            try {
                $event instanceof BookingCancelled
                    ? DeleteCalendarEvent::dispatch($event->booking)->delay(now()->addMinute())
                    : MoveCalendarEvent::dispatch($event->previous, $event->booking)->delay(now()->addMinute());
            } catch (Throwable $retryError) {
                // See CreateCalendarEvent: an inline "sync" retry must not block the emails.
                report($retryError);
            }
        }
    }
}
