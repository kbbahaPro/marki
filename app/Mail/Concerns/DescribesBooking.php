<?php

namespace App\Mail\Concerns;

use App\Enums\LocationType;
use App\Enums\SyncStatus;
use App\Models\Booking;

/**
 * Shared wording for booking emails, so guest and host copies agree.
 */
trait DescribesBooking
{
    /**
     * @return array{date: string, time: string, timezone: string}
     */
    protected function bookingTime(Booking $booking, string $timezone): array
    {
        $start = $booking->start_at->setTimezone($timezone);
        $end = $booking->end_at->setTimezone($timezone);

        return [
            'date' => $start->translatedFormat('l, F j, Y'),
            'time' => $start->format('H:i').' – '.$end->format('H:i'),
            'timezone' => str_replace('_', ' ', $timezone),
        ];
    }

    /**
     * Where the meeting happens, from the guest's point of view.
     *
     * @return array{text: string, url: ?string}
     */
    protected function bookingPlace(Booking $booking): array
    {
        return match ($booking->location_type) {
            LocationType::GoogleMeet => $booking->meeting_url
                ? ['text' => __('Google Meet'), 'url' => $booking->meeting_url]
                : ['text' => $booking->sync_status === SyncStatus::Skipped
                    ? __('Video call – :host will send you the link.', ['host' => $booking->host->name])
                    : __('Google Meet – the link will follow in a separate email.'), 'url' => null],
            LocationType::Phone => ['text' => __(':host will call you at :number.', ['host' => $booking->host->name, 'number' => $booking->guest_phone]), 'url' => null],
            LocationType::Custom => ['text' => (string) $booking->location_value, 'url' => null],
        };
    }
}
