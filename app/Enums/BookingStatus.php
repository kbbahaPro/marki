<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';

    /** The meeting took place (set by the host, or automatically after it ends). */
    case Completed = 'completed';

    /** The guest did not turn up (set by the host). */
    case NoShow = 'no_show';

    /**
     * Statuses of a meeting that is still on, or that was held as booked
     * (not cancelled or moved): what the Upcoming and Past lists show.
     *
     * @return list<self>
     */
    public static function kept(): array
    {
        return [self::Confirmed, self::Completed, self::NoShow];
    }

    public function isKept(): bool
    {
        return in_array($this, self::kept(), true);
    }
}
