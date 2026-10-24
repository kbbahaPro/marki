<?php

namespace App\Services\Calendar;

use App\Exceptions\CalendarUnavailableException;
use App\Models\Booking;
use App\Services\Scheduling\BusyTimeSource;

/**
 * An external calendar a host can connect. Google is the only implementation
 * today; Outlook would implement the same contract.
 */
interface CalendarProvider extends BusyTimeSource
{
    /**
     * Put the booking in the host's calendar (idempotent) and record the
     * result on the booking. Hosts without a connection are marked "skipped".
     *
     * @throws CalendarUnavailableException
     */
    public function createEvent(Booking $booking): void;

    /**
     * Move the previous booking's event to the new booking's time (keeping
     * its video link), or create one if there was none, and record the result
     * on the new booking.
     *
     * @throws CalendarUnavailableException
     */
    public function moveEvent(Booking $previous, Booking $booking): void;

    /**
     * Remove the booking's event from the host's calendar, if there is one.
     *
     * @throws CalendarUnavailableException
     */
    public function deleteEvent(Booking $booking): void;
}
