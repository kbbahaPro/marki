<?php

namespace Tests\Feature\Calendar;

use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Jobs\SyncBookingToCalendar;
use App\Mail\CalendarDisconnectedMail;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/**
 * The host works Mon–Fri 09:00–17:00 UTC; "now" is 2026-11-01 10:00 UTC.
 */
class GoogleCalendarSyncTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private User $host;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();
        $this->fakeGoogle();

        $this->host = User::factory()->create(['username' => 'jane']);
        $this->eventType = EventType::factory()->for($this->host)->create(['slug' => 'intro', 'title' => 'Intro call']);
    }

    // --- Busy times -------------------------------------------------------

    public function test_google_busy_times_remove_slots_and_are_cached(): void
    {
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse([['2026-11-09T10:00:00Z', '2026-11-09T11:00:00Z']]));

        $slots = $this->slots();
        $this->assertNotContains('2026-11-09T10:00:00+00:00', $slots);
        $this->assertNotContains('2026-11-09T10:30:00+00:00', $slots);
        $this->assertContains('2026-11-09T11:00:00+00:00', $slots);

        // Second request is served from the cache.
        $this->slots();
        $this->assertCount(1, $this->googleRequests());

        $request = $this->googleRequests()[0];
        $this->assertStringEndsWith('/calendar/v3/freeBusy', $request->getUri()->getPath());
        $this->assertSame('Bearer access-token', $request->getHeaderLine('Authorization'));
        $this->assertSame([['id' => 'primary']], $this->requestJson($request)['items']);
    }

    public function test_cache_can_be_invalidated_per_host(): void
    {
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse(), $this->freeBusyResponse());

        $this->slots();
        app(GoogleCalendarService::class)->forgetBusyTimes($this->host);
        $this->slots();

        $this->assertCount(2, $this->googleRequests());
    }

    public function test_an_expired_access_token_is_refreshed_and_saved(): void
    {
        $this->connectGoogle($this->host, ['token_expires_at' => now()->subMinute()]);
        $this->queueGoogle(
            $this->googleJson(['access_token' => 'ya29.refreshed', 'expires_in' => 3599]),
            $this->freeBusyResponse(),
        );

        $this->assertNotEmpty($this->slots());

        [$refresh, $freeBusy] = $this->googleRequests();
        parse_str((string) $refresh->getBody(), $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('refresh-token', $form['refresh_token']);
        $this->assertSame('Bearer ya29.refreshed', $freeBusy->getHeaderLine('Authorization'));

        $connection = $this->host->googleConnection()->first();
        $this->assertSame('ya29.refreshed', $connection->access_token);
        $this->assertSame('refresh-token', $connection->refresh_token);
        $this->assertTrue($connection->token_expires_at->isFuture());
    }

    public function test_a_revoked_token_fails_closed_and_notifies_the_host_once(): void
    {
        $this->connectGoogle($this->host, ['token_expires_at' => now()->subMinute()]);
        $this->queueGoogle($this->googleJson(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400));

        $this->assertSame([], $this->slots());
        $this->assertSame([], $this->slots(month: '2026-12'));

        $connection = $this->host->googleConnection()->first();
        $this->assertSame(ConnectionStatus::Revoked, $connection->status);
        $this->assertStringContainsString('invalid_grant', $connection->last_error);

        // Only the first request reached Google; one email was sent.
        $this->assertCount(1, $this->googleRequests());
        Mail::assertQueued(CalendarDisconnectedMail::class, 1);
        Mail::assertQueued(CalendarDisconnectedMail::class, fn ($mail) => $mail->hasTo($this->host->email));

        $this->post('/jane/intro', $this->form())->assertSessionHasErrors('start');
        $this->assertSame(0, Booking::count());
    }

    public function test_a_401_from_the_calendar_api_marks_the_connection_revoked(): void
    {
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->googleError(401, 'authError'));

        $this->assertSame([], $this->slots());
        $this->assertSame(ConnectionStatus::Revoked, $this->host->googleConnection()->first()->status);
    }

    public function test_google_outages_fail_closed_without_revoking(): void
    {
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->googleError(500), $this->googleError(403, 'rateLimitExceeded'));

        $this->assertSame([], $this->slots());
        $this->assertSame([], $this->slots(month: '2026-12'));

        $this->assertSame(ConnectionStatus::Active, $this->host->googleConnection()->first()->status);
        Mail::assertNothingQueued();
    }

    public function test_hosts_without_google_are_unaffected(): void
    {
        $this->assertNotEmpty($this->slots());
        $this->assertSame([], $this->googleRequests());
    }

    // --- Events -----------------------------------------------------------

    public function test_booking_creates_a_google_event_with_a_meet_link(): void
    {
        $this->connectGoogle($this->host);
        $question = $this->eventType->questions()->create(['label' => 'Company', 'type' => 'text', 'position' => 0]);
        $this->queueGoogle(
            $this->freeBusyResponse(),
            $this->googleJson(['id' => 'event-id-from-google', 'status' => 'confirmed', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']),
        );

        $this->post('/jane/intro', $this->form([
            'name' => 'Grace <script>',
            'notes' => '<b>hello</b>',
            'answers' => [$question->id => 'Acme & Co'],
        ]))->assertRedirect();

        $booking = Booking::sole();
        $this->assertSame(SyncStatus::Synced, $booking->sync_status);
        $this->assertSame('event-id-from-google', $booking->external_event_id);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $booking->meeting_url);

        $insert = $this->googleRequests()[1];
        $this->assertSame('POST', $insert->getMethod());
        $this->assertStringEndsWith('/calendar/v3/calendars/primary/events', $insert->getUri()->getPath());
        parse_str($insert->getUri()->getQuery(), $query);
        $this->assertSame('none', $query['sendUpdates']);
        $this->assertSame('1', $query['conferenceDataVersion']);

        $body = $this->requestJson($insert);
        $this->assertSame(app(GoogleCalendarService::class)->eventId($booking), $body['id']);
        $this->assertMatchesRegularExpression('/^[a-v0-9]{5,1024}$/', $body['id']);
        $this->assertSame('2026-11-09T09:00:00+00:00', $body['start']['dateTime']);
        $this->assertSame('2026-11-09T09:30:00+00:00', $body['end']['dateTime']);
        $this->assertEquals([['email' => 'grace@example.com', 'displayName' => 'Grace <script>']], $body['attendees']);
        $this->assertSame('hangoutsMeet', $body['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        $this->assertSame($booking->uid, $body['conferenceData']['createRequest']['requestId']);

        // Guest text is escaped in the HTML description.
        $this->assertStringContainsString('Grace &lt;script&gt;', $body['description']);
        $this->assertStringContainsString('&lt;b&gt;hello&lt;/b&gt;', $body['description']);
        $this->assertStringContainsString('Company: Acme &amp; Co', $body['description']);
    }

    public function test_custom_location_events_have_no_conference(): void
    {
        $this->eventType->update(['location_type' => 'custom', 'location_value' => '1 Main St']);
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse(), $this->googleJson(['id' => 'e1']));

        $this->post('/jane/intro', $this->form());

        $body = $this->requestJson($this->googleRequests()[1]);
        $this->assertSame('1 Main St', $body['location']);
        $this->assertArrayNotHasKey('conferenceData', $body);
        $this->assertNull(Booking::sole()->meeting_url);
    }

    public function test_bookings_for_hosts_without_google_are_marked_skipped(): void
    {
        $this->post('/jane/intro', $this->form())->assertRedirect();

        $this->assertSame(SyncStatus::Skipped, Booking::sole()->sync_status);
        $this->assertSame([], $this->googleRequests());
    }

    public function test_a_failed_event_insert_keeps_the_booking_and_queues_a_retry(): void
    {
        Queue::fake();
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse(), $this->googleError(503));

        $this->post('/jane/intro', $this->form())->assertRedirect();

        $booking = Booking::sole();
        $this->assertSame(SyncStatus::Failed, $booking->sync_status);
        $this->assertStringContainsString('503', $booking->sync_error);
        Queue::assertPushed(SyncBookingToCalendar::class, fn ($job) => $job->booking->is($booking));
    }

    public function test_the_retry_job_treats_an_existing_event_as_success(): void
    {
        $this->connectGoogle($this->host);
        $booking = Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create(['sync_status' => SyncStatus::Failed]);
        $this->queueGoogle(
            $this->googleError(409, 'duplicate'),
            $this->googleJson(['id' => 'already-there', 'hangoutLink' => 'https://meet.google.com/xyz']),
        );

        SyncBookingToCalendar::dispatchSync($booking);

        $booking->refresh();
        $this->assertSame(SyncStatus::Synced, $booking->sync_status);
        $this->assertSame('already-there', $booking->external_event_id);
        $this->assertSame('https://meet.google.com/xyz', $booking->meeting_url);
        $this->assertStringContainsString(app(GoogleCalendarService::class)->eventId($booking), (string) $this->googleRequests()[1]->getUri());
    }

    public function test_deleting_an_event_tolerates_one_already_gone(): void
    {
        $this->connectGoogle($this->host);
        $booking = Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create(['external_event_id' => 'gone']);
        $this->queueGoogle($this->googleError(410, 'deleted'));

        app(GoogleCalendarService::class)->deleteEvent($booking);

        $request = $this->googleRequests()[0];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/calendars/primary/events/gone', $request->getUri()->getPath());
    }

    /**
     * @return list<string>
     */
    private function slots(string $month = '2026-11'): array
    {
        $days = $this->getJson("/jane/intro/slots?month={$month}&timezone=UTC")->assertOk()->json('days');

        return array_merge([], ...array_values($days));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'start' => '2026-11-09T09:00:00Z',
            'timezone' => 'UTC',
            'name' => 'Grace Guest',
            'email' => 'grace@example.com',
        ], $overrides);
    }
}
