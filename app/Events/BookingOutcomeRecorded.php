<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a past booking is marked completed or no-show, by a host or
 * automatically (bookings:complete). $booking->status says which.
 */
class BookingOutcomeRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
