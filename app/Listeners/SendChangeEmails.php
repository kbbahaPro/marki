<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingRescheduled;
use App\Mail\BookingCancelledGuest;
use App\Mail\BookingCancelledHost;
use App\Mail\BookingRescheduledGuest;
use App\Mail\BookingRescheduledHost;
use Illuminate\Support\Facades\Mail;

/**
 * Tells guest and host about cancellations and reschedules. Registered after
 * UpdateCalendarAfterChange so emails reflect the calendar's final state.
 */
class SendChangeEmails
{
    public function handle(BookingCancelled|BookingRescheduled $event): void
    {
        $booking = $event->booking->fresh(['eventType', 'host']);

        if ($event instanceof BookingCancelled) {
            Mail::to($booking->guest_email, $booking->guest_name)->send(new BookingCancelledGuest($booking));

            // Hosts who cancelled themselves do not need to be told.
            if ($booking->cancelled_by !== 'host') {
                Mail::to($booking->host)->send(new BookingCancelledHost($booking));
            }

            return;
        }

        $previous = $event->previous->fresh();

        Mail::to($booking->guest_email, $booking->guest_name)
            ->send(new BookingRescheduledGuest($previous, $booking, $event->manageToken));
        Mail::to($booking->host)->send(new BookingRescheduledHost($previous, $booking));
    }
}
