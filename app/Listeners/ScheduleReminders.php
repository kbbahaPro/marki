<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingRescheduled;

/**
 * Keeps pending reminder rows in step with the booking's life cycle.
 */
class ScheduleReminders
{
    public function handle(BookingCreated|BookingRescheduled|BookingCancelled $event): void
    {
        match (true) {
            $event instanceof BookingCreated => $event->booking->scheduleReminders(),
            $event instanceof BookingRescheduled => $this->move($event),
            $event instanceof BookingCancelled => $event->booking->reminders()->whereNull('sent_at')->delete(),
        };
    }

    private function move(BookingRescheduled $event): void
    {
        $event->previous->reminders()->whereNull('sent_at')->delete();
        $event->booking->scheduleReminders();
    }
}
