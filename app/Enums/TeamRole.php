<?php

namespace App\Enums;

enum TeamRole: string
{
    /** Manages the team's event types and sees all of its bookings. */
    case Admin = 'admin';

    /** Can host the team's event types and sees the bookings they attend. */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Team admin'),
            self::Member => __('Member'),
        };
    }
}
