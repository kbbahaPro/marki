<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a booking is committed. Calendar sync and emails listen to it.
 */
class BookingCreated
{
    use Dispatchable, SerializesModels;

    /**
     * The raw manage token is only available here, at creation time.
     */
    public function __construct(
        public Booking $booking,
        public string $manageToken,
    ) {}
}
