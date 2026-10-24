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
 * Tells the guest and every host about cancellations and reschedules.
 * Registered after UpdateCalendarAfterChange so emails reflect the
 * calendar's final state.
 */
class SendChangeEmails
{
    public function handle(BookingCancelled|BookingRescheduled $event): void
    {
        $booking = $event->booking->fresh(['eventType', 'host', 'hosts']);

        if ($event instanceof BookingCancelled) {
            Mail::to($booking->guest_email, $booking->guest_name)->send(new BookingCancelledGuest($booking));

            // Whoever cancelled knows already; the other hosts still need telling.
            $actorId = $booking->cancelled_by === 'host' ? ($booking->cancelled_by_user_id ?? $booking->host_id) : null;

            foreach ($booking->hosts as $host) {
                if ($host->id !== $actorId) {
                    Mail::to($host)->send(new BookingCancelledHost($booking, $host));
                }
            }

            return;
        }

        $previous = $event->previous->fresh(['eventType', 'host', 'hosts']);

        Mail::to($booking->guest_email, $booking->guest_name)
            ->send(new BookingRescheduledGuest($previous, $booking, $event->manageToken, $event->by));

        $actorId = $event->by === 'host' ? ($event->actorId ?? $booking->host_id) : null;

        foreach ($booking->hosts as $host) {
            if ($host->id !== $actorId) {
                Mail::to($host)->send(new BookingRescheduledHost($previous, $booking, $host));
            }
        }

        // Round robin hosts who no longer have the booking.
        foreach ($previous->hosts->diff($booking->hosts) as $host) {
            Mail::to($host)->send(new BookingCancelledHost($previous, $host, reassigned: true));
        }
    }
}
