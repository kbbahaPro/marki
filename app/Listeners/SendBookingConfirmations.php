<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use App\Mail\BookingConfirmedGuest;
use App\Mail\BookingConfirmedHost;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the guest and host confirmations. Registered after
 * CreateCalendarEvent so the Meet link is already on the booking.
 */
class SendBookingConfirmations
{
    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking->fresh(['eventType', 'host', 'answers']);

        Mail::to($booking->guest_email, $booking->guest_name)
            ->send(new BookingConfirmedGuest($booking, $event->manageToken));

        Mail::to($booking->host)->send(new BookingConfirmedHost($booking));
    }
}
