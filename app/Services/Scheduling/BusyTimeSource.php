<?php

namespace App\Services\Scheduling;

use App\Exceptions\CalendarUnavailableException;
use App\Models\User;

/**
 * An external calendar that reports when a host is busy (Google today,
 * Outlook later). Bookings made in this app are handled separately.
 */
interface BusyTimeSource
{
    /**
     * @return list<Period> Busy periods in UTC overlapping $range.
     *
     * @throws CalendarUnavailableException When the host's calendar cannot be read.
     */
    public function busyPeriods(User $host, Period $range): array;
}
