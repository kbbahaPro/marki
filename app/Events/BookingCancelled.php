<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a booking is cancelled (by the guest or the host).
 */
class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
