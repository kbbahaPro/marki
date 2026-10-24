<?php

namespace App\Services\Calendar\Google;

use App\Enums\ConnectionStatus;
use App\Enums\LocationType;
use App\Enums\SyncStatus;
use App\Exceptions\CalendarUnavailableException;
use App\Mail\CalendarDisconnectedMail;
use App\Models\Booking;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Calendar\CalendarProvider;
use App\Services\Scheduling\Period;
use Closure;
use Google\Exception as GoogleException;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class GoogleCalendarService implements CalendarProvider
{
    /** 403 reasons that mean "try later", not "access is gone". */
    private const TRANSIENT_403_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'usageLimits'];

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * Busy times from the host's Google calendars, cached briefly to stay
     * inside Google's rate limits.
     */
    public function busyPeriods(User $host, Period $range): array
    {
        $connection = $host->googleConnection;

        if ($connection === null) {
            return [];
        }

        if (! $connection->isActive()) {
            throw new CalendarUnavailableException('Google Calendar must be reconnected.');
        }

        $key = sprintf('google-freebusy:%d:%s:%d:%d', $connection->id, $this->cacheVersion($host), $range->start->getTimestamp(), $range->end->getTimestamp());

        $busy = Cache::remember($key, config('services.google.freebusy_cache_seconds'), fn () => $this->fetchBusy($connection, $range));

        return array_map(fn (array $p) => new Period('@'.$p[0], '@'.$p[1]), $busy);
    }

    /**
     * Make cached free/busy answers for a host stale (after connecting, disconnecting or cancelling).
     */
    public function forgetBusyTimes(User $host): void
    {
        Cache::forever("google-freebusy-version:{$host->id}", (string) hrtime(true));
    }

    public function createEvent(Booking $booking): void
    {
        $connection = $booking->host->googleConnection;

        if ($connection === null) {
            $booking->forceFill(['sync_status' => SyncStatus::Skipped, 'sync_error' => null])->save();

            return;
        }

        $this->ensureActive($connection);

        $event = $this->call($connection, function (Calendar $calendar) use ($booking, $connection) {
            try {
                return $calendar->events->insert($connection->write_calendar_id, $this->eventFor($booking), [
                    'conferenceDataVersion' => 1,
                    // Our own email (with .ics) is the guest's invitation; avoid a second one from Google.
                    'sendUpdates' => 'none',
                ]);
            } catch (GoogleServiceException $e) {
                // The id is derived from the booking, so 409 means an earlier attempt already created it.
                if ($e->getCode() === 409) {
                    return $calendar->events->get($connection->write_calendar_id, $this->eventId($booking));
                }

                throw $e;
            }
        });

        $booking->forceFill([
            'external_event_id' => $event->getId(),
            'meeting_url' => $booking->location_type === LocationType::GoogleMeet ? $this->meetLink($event) : $booking->meeting_url,
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
        ])->save();
    }

    public function moveEvent(Booking $previous, Booking $booking): void
    {
        // A round robin reschedule can hand the booking to another host: the event moves calendars.
        if ($previous->host_id !== $booking->host_id) {
            $this->deleteEvent($previous);
            $this->createEvent($booking);

            return;
        }

        $connection = $booking->host->googleConnection;

        if ($connection === null) {
            $booking->forceFill(['sync_status' => SyncStatus::Skipped, 'sync_error' => null])->save();

            return;
        }

        if ($previous->external_event_id === null) {
            $this->createEvent($booking);

            return;
        }

        $this->ensureActive($connection);

        $event = $this->call($connection, function (Calendar $calendar) use ($previous, $booking, $connection) {
            try {
                return $calendar->events->patch($connection->write_calendar_id, $previous->external_event_id, new Event([
                    'start' => ['dateTime' => $booking->start_at->toRfc3339String(), 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => $booking->end_at->toRfc3339String(), 'timeZone' => 'UTC'],
                    'attendees' => $this->attendees($booking),
                ]), ['sendUpdates' => 'none']);
            } catch (GoogleServiceException $e) {
                // The host deleted the event in Google Calendar: create a fresh one below.
                if (in_array($e->getCode(), [404, 410], true)) {
                    return null;
                }

                throw $e;
            }
        });

        if ($event === null) {
            $this->createEvent($booking);

            return;
        }

        $booking->forceFill([
            'external_event_id' => $event->getId(),
            'meeting_url' => $this->meetLink($event) ?? $booking->meeting_url,
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
        ])->save();

        $booking->hosts->each(fn (User $host) => $this->forgetBusyTimes($host));
    }

    public function deleteEvent(Booking $booking): void
    {
        $connection = $booking->host->googleConnection;

        if ($connection === null || $booking->external_event_id === null) {
            return;
        }

        $this->ensureActive($connection);

        $this->call($connection, function (Calendar $calendar) use ($booking, $connection) {
            try {
                $calendar->events->delete($connection->write_calendar_id, $booking->external_event_id, ['sendUpdates' => 'none']);
            } catch (GoogleServiceException $e) {
                // Already deleted by the host in Google Calendar.
                if (! in_array($e->getCode(), [404, 410], true)) {
                    throw $e;
                }
            }
        });

        $booking->hosts->each(fn (User $host) => $this->forgetBusyTimes($host));
    }

    /**
     * Google event ids allow only a–v and 0–9; a hex digest of the booking
     * uid fits and makes retries idempotent.
     */
    public function eventId(Booking $booking): string
    {
        return 'bk'.hash('sha1', 'booking:'.$booking->uid);
    }

    /**
     * Mark a connection as needing reconnection and tell the host, once.
     */
    public function markRevoked(CalendarConnection $connection, string $reason): void
    {
        $changed = CalendarConnection::query()
            ->whereKey($connection->id)
            ->where('status', ConnectionStatus::Active)
            ->update(['status' => ConnectionStatus::Revoked, 'last_error' => Str::limit($reason, 1000)]);

        $connection->status = ConnectionStatus::Revoked;

        if ($changed) {
            Mail::to($connection->user)->send(new CalendarDisconnectedMail($connection));
        }
    }

    /**
     * @return list<array{0: int, 1: int}> Busy [startTs, endTs] pairs.
     */
    private function fetchBusy(CalendarConnection $connection, Period $range): array
    {
        $response = $this->call($connection, fn (Calendar $calendar) => $calendar->freebusy->query(new FreeBusyRequest([
            'timeMin' => $range->start->toRfc3339String(),
            'timeMax' => $range->end->toRfc3339String(),
            'items' => array_map(fn (string $id) => ['id' => $id], $connection->busyCalendars()),
        ])));

        $busy = [];

        foreach ($response->getCalendars() as $id => $calendar) {
            // Per-calendar errors (e.g. notFound) would silently hide busy times.
            if ($calendar->getErrors()) {
                throw new CalendarUnavailableException("Google Calendar \"{$id}\" could not be read.");
            }

            foreach ($calendar->getBusy() as $period) {
                $busy[] = [strtotime($period->getStart()), strtotime($period->getEnd())];
            }
        }

        $connection->forceFill(['last_synced_at' => now()])->save();

        return $busy;
    }

    /**
     * Run a Calendar API call with a fresh access token, translating failures.
     *
     * @template T
     *
     * @param  Closure(Calendar): T  $callback
     * @return T
     */
    private function call(CalendarConnection $connection, Closure $callback): mixed
    {
        $accessToken = $this->freshAccessToken($connection);

        try {
            return $callback($this->api->calendar($accessToken));
        } catch (GoogleServiceException $e) {
            $reason = $e->getErrors()[0]['reason'] ?? '';

            if ($e->getCode() === 401 || ($e->getCode() === 403 && ! in_array($reason, self::TRANSIENT_403_REASONS, true))) {
                $this->markRevoked($connection, "Google API {$e->getCode()}: {$reason}");
            }

            throw new CalendarUnavailableException("Google Calendar request failed ({$e->getCode()} {$reason}).", previous: $e);
        } catch (GuzzleException|GoogleException $e) {
            throw new CalendarUnavailableException('Google Calendar request failed: '.$e->getMessage(), previous: $e);
        }
    }

    private function freshAccessToken(CalendarConnection $connection): string
    {
        if ($connection->token_expires_at->subMinute()->isFuture()) {
            return $connection->access_token;
        }

        try {
            $token = $this->api->refresh($connection->refresh_token);
        } catch (GuzzleException|GoogleException $e) {
            throw new CalendarUnavailableException('Could not refresh the Google token: '.$e->getMessage(), previous: $e);
        }

        if (isset($token['error']) || ! isset($token['access_token'])) {
            $error = $token['error'] ?? 'no access token';

            // invalid_grant: the user revoked access, or the refresh token expired.
            if ($error === 'invalid_grant') {
                $this->markRevoked($connection, 'Token refresh failed: invalid_grant');
            }

            throw new CalendarUnavailableException("Could not refresh the Google token ({$error}).");
        }

        $connection->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();

        return $token['access_token'];
    }

    private function ensureActive(CalendarConnection $connection): void
    {
        if (! $connection->isActive()) {
            throw new CalendarUnavailableException('Google Calendar must be reconnected.');
        }
    }

    private function cacheVersion(User $host): string
    {
        return (string) Cache::get("google-freebusy-version:{$host->id}", '0');
    }

    private function eventFor(Booking $booking): Event
    {
        $booking->loadMissing(['eventType', 'answers']);

        // Guest-supplied text is escaped: Google renders descriptions as HTML.
        $lines = [
            __('Booked via :app', ['app' => e(config('app.name'))]),
            '',
            __('Guest').': '.e($booking->guest_name).' <'.e($booking->guest_email).'>',
        ];

        if ($booking->guest_phone) {
            $lines[] = __('Phone').': '.e($booking->guest_phone);
        }

        foreach ($booking->answers as $answer) {
            $lines[] = e($answer->label).': '.e((string) $answer->value);
        }

        if ($booking->guest_notes) {
            $lines[] = '';
            $lines[] = e($booking->guest_notes);
        }

        $event = [
            'id' => $this->eventId($booking),
            'summary' => "{$booking->eventType->title} – {$booking->guest_name}",
            'description' => implode("\n", $lines),
            'start' => ['dateTime' => $booking->start_at->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $booking->end_at->toRfc3339String(), 'timeZone' => 'UTC'],
            // Co-hosts of team bookings are attendees of the organizer's event.
            'attendees' => $this->attendees($booking),
            'guestsCanModify' => false,
            'reminders' => ['useDefault' => true],
        ];

        $event['location'] = match ($booking->location_type) {
            LocationType::Custom => $booking->location_value,
            LocationType::Phone => $booking->guest_phone,
            LocationType::GoogleMeet => null,
        };

        if ($booking->location_type === LocationType::GoogleMeet) {
            $event['conferenceData'] = ['createRequest' => [
                'requestId' => $booking->uid,
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
        }

        return new Event(array_filter($event, fn ($value) => $value !== null));
    }

    /**
     * @return list<array{email: string, displayName: string}>
     */
    private function attendees(Booking $booking): array
    {
        return [
            ['email' => $booking->guest_email, 'displayName' => $booking->guest_name],
            ...$booking->coHosts()->map(fn (User $host) => ['email' => $host->email, 'displayName' => $host->name]),
        ];
    }

    private function meetLink(Event $event): ?string
    {
        if ($event->getHangoutLink()) {
            return $event->getHangoutLink();
        }

        foreach ($event->getConferenceData()?->getEntryPoints() ?? [] as $entryPoint) {
            if ($entryPoint->getEntryPointType() === 'video') {
                return $entryPoint->getUri();
            }
        }

        return null;
    }
}
