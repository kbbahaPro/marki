<?php

namespace App\Services\Ics;

use App\Enums\LocationType;
use App\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * Builds RFC 5545 iCalendar files for bookings.
 *
 * The guest's copy uses METHOD:REQUEST so mail clients show it as an
 * invitation; METHOD:CANCEL with the same UID and a higher SEQUENCE removes
 * it again (used when bookings are cancelled or rescheduled).
 */
class IcsGenerator
{
    public const REQUEST = 'REQUEST';

    public const CANCEL = 'CANCEL';

    public function forBooking(Booking $booking, string $method = self::REQUEST, ?int $sequence = null): string
    {
        $sequence ??= $booking->ical_sequence ?? 0;

        $booking->loadMissing(['eventType', 'host']);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$this->text(config('app.name')).'//Booking//EN',
            'CALSCALE:GREGORIAN',
            "METHOD:{$method}",
            'BEGIN:VEVENT',
            'UID:'.$this->uid($booking),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.$this->utc(CarbonImmutable::now()),
            'DTSTART:'.$this->utc($booking->start_at),
            'DTEND:'.$this->utc($booking->end_at),
            'SUMMARY:'.$this->text("{$booking->eventType->title} – {$booking->hostNames()} & {$booking->guest_name}"),
            'DESCRIPTION:'.$this->text($this->description($booking)),
            'ORGANIZER;CN='.$this->param($booking->host->name).':mailto:'.$booking->host->email,
            'ATTENDEE;CN='.$this->param($booking->guest_name).';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:'.$booking->guest_email,
            ...$booking->coHosts()->map(fn ($host) => 'ATTENDEE;CN='.$this->param($host->name).';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:'.$host->email),
            'STATUS:'.($method === self::CANCEL ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
        ];

        if ($location = $this->location($booking)) {
            $lines[] = 'LOCATION:'.$this->text($location);
        }

        if ($booking->meeting_url) {
            $lines[] = 'URL:'.$booking->meeting_url;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * Stable across reschedule/cancel so calendar apps update the same event.
     */
    public function uid(Booking $booking): string
    {
        return ($booking->ical_uid ?? $booking->uid).'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    private function description(Booking $booking): string
    {
        $parts = [];

        if ($booking->meeting_url) {
            $parts[] = __('Join: :url', ['url' => $booking->meeting_url]);
        }

        if ($booking->guest_notes) {
            $parts[] = $booking->guest_notes;
        }

        $parts[] = __('Booked via :app', ['app' => config('app.name')]);

        return implode("\n\n", $parts);
    }

    private function location(Booking $booking): ?string
    {
        return match ($booking->location_type) {
            LocationType::GoogleMeet => $booking->meeting_url,
            LocationType::Phone => $booking->guest_phone ? __('Phone: :number', ['number' => $booking->guest_phone]) : __('Phone call'),
            LocationType::Custom => $booking->location_value,
        };
    }

    private function utc(CarbonImmutable $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value (RFC 5545 §3.3.11).
     */
    private function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /**
     * Quote a parameter value such as CN (RFC 5545 §3.2); DQUOTE is not allowed inside.
     */
    private function param(string $value): string
    {
        return '"'.str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value).'"';
    }

    /**
     * Fold lines longer than 75 octets without splitting UTF-8 characters (RFC 5545 §3.1).
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = 74; // continuation lines start with a space
            }

            $current .= $char;
        }

        return $folded.$current;
    }
}
