<?php

namespace Tests\Feature\Public;

use App\Enums\BookingStatus;
use App\Enums\SyncStatus;
use App\Mail\BookingCancelledGuest;
use App\Mail\BookingCancelledHost;
use App\Mail\BookingConfirmedGuest;
use App\Mail\BookingRescheduledGuest;
use App\Mail\BookingRescheduledHost;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use App\Services\Ics\IcsGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/**
 * Host works Mon–Fri 09:00–17:00 UTC; "now" is 2026-11-01 10:00 UTC. The
 * guest booked Monday 2026-11-09 09:00 UTC.
 */
class ManageBookingTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private User $host;

    private EventType $eventType;

    private Booking $booking;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();
        $this->fakeGoogle();

        $this->host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host']);
        $this->eventType = EventType::factory()->for($this->host)->create(['slug' => 'intro', 'title' => 'Intro call', 'duration_minutes' => 30]);
        $question = $this->eventType->questions()->create(['label' => 'Company', 'type' => 'text', 'position' => 0]);

        [$this->booking, $this->token] = app(BookingService::class)->book(
            $this->eventType,
            CarbonImmutable::parse('2026-11-09 09:00:00'),
            new GuestDetails('Grace Guest', 'grace@example.com', 'Asia/Tokyo', answers: [
                ['question_id' => $question->id, 'label' => 'Company', 'value' => 'Acme', 'position' => 0],
            ]),
        );
    }

    // --- Links --------------------------------------------------------

    public function test_confirmation_email_and_page_carry_the_manage_links(): void
    {
        Mail::assertQueued(BookingConfirmedGuest::class, function (BookingConfirmedGuest $mail) {
            $html = $mail->render();

            return str_contains($html, route('bookings.manage', $this->token))
                && str_contains($html, route('bookings.reschedule', $this->token));
        });

        $redirect = $this->post('/jane/intro', [
            'start' => '2026-11-10T09:00:00Z', 'timezone' => 'UTC', 'name' => 'Ann', 'email' => 'ann@example.com',
        ])->assertRedirect();
        $confirmation = $redirect->headers->get('Location');

        $this->get($confirmation)->assertSee('/manage/');

        // The token is flashed once; reloading the page (or sharing its URL) does not reveal it.
        $this->get($confirmation)->assertDontSee('/manage/');
    }

    public function test_manage_page_needs_the_right_token(): void
    {
        $this->get("/manage/{$this->token}")->assertOk()->assertSee('Intro call')->assertSee('Cancel booking');

        $this->get('/manage/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/manage/short')->assertNotFound();
        // The stored hash is not a valid link.
        $this->get("/manage/{$this->booking->manage_token_hash}")->assertNotFound();
    }

    public function test_manage_page_shows_the_time_in_the_guests_zone(): void
    {
        $this->get("/manage/{$this->token}")->assertSee('18:00 – 18:30')->assertSee('Asia/Tokyo');
    }

    // --- Cancel ---------------------------------------------------------

    public function test_guest_can_cancel_with_a_reason(): void
    {
        $this->post("/manage/{$this->token}/cancel", ['reason' => 'Something came up'])
            ->assertRedirect("/manage/{$this->token}");

        $booking = $this->booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('guest', $booking->cancelled_by);
        $this->assertSame('Something came up', $booking->cancel_reason);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(1, $booking->ical_sequence);

        Mail::assertQueued(BookingCancelledGuest::class, function (BookingCancelledGuest $mail) {
            $ics = $mail->attachments()[0];

            return $mail->hasTo('grace@example.com')
                && $ics->mime === 'text/calendar; charset=utf-8; method=CANCEL'
                && str_contains($mail->render(), 'You cancelled');
        });
        Mail::assertQueued(BookingCancelledHost::class, fn ($mail) => $mail->hasTo($this->host->email)
            && str_contains($mail->render(), 'Something came up'));

        $this->get("/manage/{$this->token}")->assertSee('This booking is cancelled')->assertDontSee('Cancel booking');
    }

    public function test_cancelling_frees_the_slot(): void
    {
        $this->post("/manage/{$this->token}/cancel");

        $slots = $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->json('days.2026-11-09');
        $this->assertContains('2026-11-09T09:00:00+00:00', $slots);
    }

    public function test_a_booking_cannot_be_cancelled_twice_or_after_it_started(): void
    {
        $this->post("/manage/{$this->token}/cancel");
        $this->post("/manage/{$this->token}/cancel")->assertSessionHas('error');
        Mail::assertQueued(BookingCancelledGuest::class, 1);

        [, $token] = app(BookingService::class)->book($this->eventType, CarbonImmutable::parse('2026-11-10 09:00'), new GuestDetails('Late', 'late@example.com', 'UTC'));
        $this->travelTo('2026-11-10 09:05:00');

        $this->post("/manage/{$token}/cancel")->assertSessionHas('error');
        $this->get("/manage/{$token}")->assertSee('already started');
    }

    public function test_cancelling_removes_the_google_event(): void
    {
        $this->connectGoogle($this->host);
        $this->booking->forceFill(['external_event_id' => 'evt-1', 'sync_status' => SyncStatus::Synced])->save();
        $this->queueGoogle($this->googleJson([], 204));

        $this->post("/manage/{$this->token}/cancel");

        $request = $this->googleRequests()[0];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/calendars/primary/events/evt-1', $request->getUri()->getPath());

        // Removed from Google, so the host gets no .ics that could confuse their calendar.
        Mail::assertQueued(BookingCancelledHost::class, fn ($mail) => $mail->attachments() === []);
    }

    // --- Reschedule -----------------------------------------------------

    public function test_reschedule_page_lists_times_including_ones_overlapping_the_current_booking(): void
    {
        $this->get("/manage/{$this->token}/reschedule")->assertOk()->assertSee('Rescheduling')->assertSee('Reschedule booking');

        $slots = $this->getJson("/manage/{$this->token}/reschedule/slots?month=2026-11&timezone=UTC")->json('days.2026-11-09');

        // The booking's own time does not block moving it.
        $this->assertContains('2026-11-09T09:00:00+00:00', $slots);

        // While the public page still shows it as taken.
        $public = $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->json('days.2026-11-09');
        $this->assertNotContains('2026-11-09T09:00:00+00:00', $public);
    }

    public function test_guest_can_reschedule(): void
    {
        $response = $this->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-10T14:00:00Z', 'timezone' => 'Asia/Tokyo']);

        $old = $this->booking->fresh();
        $new = Booking::where('rescheduled_from_id', $old->id)->sole();

        $response->assertRedirect("/bookings/{$new->uid}")->assertSessionHas('manage_token');
        $newToken = session('manage_token');

        $this->assertSame(BookingStatus::Rescheduled, $old->status);
        $this->assertSame(BookingStatus::Confirmed, $new->status);
        $this->assertSame('2026-11-10 14:00:00', $new->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('Grace Guest', $new->guest_name);
        $this->assertSame('Asia/Tokyo', $new->guest_timezone);
        $this->assertSame([['Company', 'Acme']], $new->answers->map(fn ($a) => [$a->label, $a->value])->all());

        // Same calendar identity, next version: calendar apps move the existing event.
        $this->assertSame($old->uid, $new->ical_uid);
        $this->assertSame(1, $new->ical_sequence);
        $ics = app(IcsGenerator::class);
        $this->assertSame($ics->uid($old), $ics->uid($new));

        // Old link is retired; the new one works.
        $this->assertSame(hash('sha256', $newToken), $new->manage_token_hash);
        $this->get("/manage/{$this->token}")->assertSee('This booking was moved');
        $this->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-11T09:00:00Z', 'timezone' => 'UTC'])->assertSessionHas('error');
        $this->get("/manage/{$newToken}")->assertSee('Cancel booking');

        Mail::assertQueued(BookingRescheduledGuest::class, fn (BookingRescheduledGuest $mail) => $mail->hasTo('grace@example.com')
            && $mail->manageToken === $newToken
            && str_contains($mail->render(), 'Tuesday, November 10, 2026'));
        Mail::assertQueued(BookingRescheduledHost::class, fn ($mail) => $mail->hasTo($this->host->email));
    }

    public function test_guest_can_move_by_less_than_the_meeting_length(): void
    {
        $this->eventType->update(['slot_interval_minutes' => 15]);

        $this->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-09T09:15:00Z', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Booking::where('status', 'confirmed')->count());
        $this->assertSame('09:15', Booking::where('status', 'confirmed')->sole()->start_at->format('H:i'));
    }

    public function test_reschedule_to_a_taken_time_is_refused(): void
    {
        Booking::factory()->at($this->eventType, '2026-11-10 14:00')->create();

        $this->from("/manage/{$this->token}/reschedule")
            ->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-10T14:00:00Z', 'timezone' => 'UTC'])
            ->assertRedirect("/manage/{$this->token}/reschedule")
            ->assertSessionHasErrors('start');

        $this->assertSame(BookingStatus::Confirmed, $this->booking->fresh()->status);
    }

    public function test_reschedule_moves_the_google_event_and_keeps_its_meet_link(): void
    {
        $this->connectGoogle($this->host);
        $this->booking->forceFill([
            'external_event_id' => 'evt-1',
            'meeting_url' => 'https://meet.google.com/keep-this',
            'sync_status' => SyncStatus::Synced,
        ])->save();
        $this->queueGoogle(
            $this->freeBusyResponse([['2026-11-09T09:00:00Z', '2026-11-09T09:30:00Z']]), // the booking itself
            $this->googleJson(['id' => 'evt-1', 'hangoutLink' => 'https://meet.google.com/keep-this']),
        );

        $this->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-09T09:30:00Z', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $patch = $this->googleRequests()[1];
        $this->assertSame('PATCH', $patch->getMethod());
        $this->assertStringEndsWith('/calendars/primary/events/evt-1', $patch->getUri()->getPath());
        $this->assertStringContainsString('sendUpdates=none', $patch->getUri()->getQuery());
        $this->assertSame('2026-11-09T09:30:00+00:00', $this->requestJson($patch)['start']['dateTime']);

        $new = Booking::where('status', 'confirmed')->sole();
        $this->assertSame(SyncStatus::Synced, $new->sync_status);
        $this->assertSame('evt-1', $new->external_event_id);
        $this->assertSame('https://meet.google.com/keep-this', $new->meeting_url);
    }

    public function test_a_google_event_deleted_by_the_host_is_recreated_on_reschedule(): void
    {
        $this->connectGoogle($this->host);
        $this->booking->forceFill(['external_event_id' => 'gone', 'sync_status' => SyncStatus::Synced])->save();
        $this->queueGoogle(
            $this->freeBusyResponse(),
            $this->googleError(404, 'notFound'),
            $this->googleJson(['id' => 'fresh', 'hangoutLink' => 'https://meet.google.com/new-link']),
        );

        $this->post("/manage/{$this->token}/reschedule", ['start' => '2026-11-09T10:00:00Z', 'timezone' => 'UTC']);

        $new = Booking::where('status', 'confirmed')->sole();
        $this->assertSame('fresh', $new->external_event_id);
        $this->assertSame('https://meet.google.com/new-link', $new->meeting_url);
        $this->assertSame('POST', $this->googleRequests()[2]->getMethod());
    }

    public function test_manage_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/manage/'.str_repeat('b', 64));
        }

        $this->get("/manage/{$this->token}")->assertTooManyRequests();
    }
}
