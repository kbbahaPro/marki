<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
}
