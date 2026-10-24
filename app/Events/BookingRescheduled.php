<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a booking moved to a new time. The new booking has a fresh
 * manage token; the old one is marked "rescheduled".
 */
class BookingRescheduled
{
    use Dispatchable, SerializesModels;

    /**
     * @param  'guest'|'host'  $by
     */
    public function __construct(
        public Booking $previous,
        public Booking $booking,
        public string $manageToken,
        public string $by = 'guest',
        // The host or team admin who moved it, when $by is 'host'.
        public ?int $actorId = null,
    ) {}
}
