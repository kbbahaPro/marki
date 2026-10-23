<?php

namespace App\Enums;

enum LocationType: string
{
    /** A Google Meet link is generated when the calendar event is created. */
    case GoogleMeet = 'google_meet';

    /** The host calls the guest; the booking form asks for the guest's number. */
    case Phone = 'phone';

    /** Free text set by the host: an address, a video link, dial-in details… */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GoogleMeet => __('Google Meet'),
            self::Phone => __('Phone call (you call the guest)'),
            self::Custom => __('Custom location'),
        };
    }
}
