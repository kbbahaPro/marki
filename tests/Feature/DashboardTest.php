<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Jobs\SyncBookingToCalendar;
use App\Mail\BookingCancelledGuest;
use App\Mail\BookingCancelledHost;
use App\Mail\BookingRescheduledGuest;
use App\Mail\BookingRescheduledHost;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/**
 * Host in Europe/Paris (UTC+1 in November) working Mon–Fri 09:00–17:00 Paris;
 * "now" is 2026-11-05 12:00 UTC.
 */
class DashboardTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private User $host;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 12:00:00');
        Mail::fake();
        $this->fakeGoogle();

        $this->host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host', 'timezone' => 'Europe/Paris']);
        $this->eventType = EventType::factory()->for($this->host)->create(['title' => 'Intro call', 'slug' => 'intro']);
    }

    // --- Lists ----------------------------------------------------------

    public function test_upcoming_bookings_are_listed_by_day_in_the_hosts_time(): void
    {
        $later = $this->booking('2026-11-10 13:00', ['guest_name' => 'Later Larry']);
        $sooner = $this->booking('2026-11-09 09:00', ['guest_name' => 'Sooner Sam']);

        $this->actingAs($this->host)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Monday, November 9', '10:00–10:30', 'Sooner Sam', 'Tuesday, November 10', '14:00–14:30', 'Later Larry'])
            ->assertSee('You have 2 upcoming bookings.')
            ->assertSee(route('dashboard.bookings.show', $sooner))
            ->assertSee(route('dashboard.bookings.show', $later));
    }

    public function test_past_and_cancelled_tabs(): void
    {
        $this->booking('2026-11-04 09:00', ['guest_name' => 'Past Pat']);
        $this->booking('2026-11-12 09:00', ['guest_name' => 'Cancelled Cat', 'status' => BookingStatus::Cancelled, 'cancelled_by' => 'guest']);
        $this->booking('2026-11-11 09:00', ['guest_name' => 'Upcoming Uma']);

        $this->actingAs($this->host)->get('/dashboard')
            ->assertSee('Upcoming Uma')->assertDontSee('Past Pat')->assertDontSee('Cancelled Cat');

        $this->actingAs($this->host)->get('/dashboard?tab=past')
            ->assertSee('Past Pat')->assertDontSee('Cancelled Cat');

        $this->actingAs($this->host)->get('/dashboard?tab=cancelled')
            ->assertSee('Cancelled Cat')->assertSee('Cancelled by guest')->assertDontSee('Past Pat');

        // "Upcoming Uma" stays visible on every tab in the Next up card, by design.
    }

    public function test_rescheduled_history_rows_are_not_listed_twice(): void
    {
        // An earlier booking takes the Next up card, so Mover Mo only appears in the list.
        $this->booking('2026-11-06 09:00', ['guest_name' => 'First Fran']);
        $old = $this->booking('2026-11-09 09:00', ['guest_name' => 'Mover Mo', 'status' => BookingStatus::Rescheduled]);
        $this->booking('2026-11-10 09:00', ['guest_name' => 'Mover Mo', 'rescheduled_from_id' => $old->id]);

        $response = $this->actingAs($this->host)->get('/dashboard');

        $this->assertSame(1, substr_count($response->getContent(), 'Mover Mo'));
        $response->assertSee('Rescheduled');
    }

    public function test_hosts_only_see_their_own_bookings(): void
    {
        $other = EventType::factory()->create();
        $theirs = Booking::factory()->at($other, '2026-11-09 09:00')->create(['guest_name' => 'Not Yours']);

        $this->actingAs($this->host)->get('/dashboard')->assertDontSee('Not Yours');
        $this->actingAs($this->host)->get("/dashboard/bookings/{$theirs->uid}")->assertNotFound();
        $this->actingAs($this->host)->post("/dashboard/bookings/{$theirs->uid}/cancel")->assertNotFound();
        $this->actingAs($this->host)->get("/dashboard/bookings/{$theirs->uid}/reschedule")->assertNotFound();
        $this->assertSame(BookingStatus::Confirmed, $theirs->fresh()->status);
    }

    public function test_dashboard_warns_about_calendar_and_event_types(): void
    {
        $this->eventType->update(['is_active' => false]);

        $this->actingAs($this->host)->get('/dashboard')
            ->assertSee('Connect Google Calendar')
            ->assertSee('no active event types');

        $this->connectGoogle($this->host, ['status' => ConnectionStatus::Revoked]);

        // A fresh instance, as a real request would load (the first request cached the relation).
        $this->actingAs($this->host->fresh())->get('/dashboard')->assertSee('Your Google Calendar is disconnected');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    // --- Detail and actions ---------------------------------------------

    public function test_booking_detail_shows_answers_and_both_time_zones(): void
    {
        $booking = $this->booking('2026-11-09 09:00', [
            'guest_name' => 'Grace Guest',
            'guest_timezone' => 'America/New_York',
            'guest_notes' => '<b>Bring slides</b>',
        ]);
        $booking->answers()->create(['label' => 'Company', 'value' => 'Acme', 'position' => 0]);

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")
            ->assertOk()
            ->assertSee('10:00 – 10:30')
            ->assertSee('Mon 04:00 (America/New York)', escape: false)
            ->assertSee('Acme')
            ->assertSee('&lt;b&gt;Bring slides&lt;/b&gt;', escape: false);
    }

    public function test_host_can_cancel_and_only_the_guest_is_emailed(): void
    {
        $booking = $this->booking('2026-11-09 09:00');

        $this->actingAs($this->host)
            ->post("/dashboard/bookings/{$booking->uid}/cancel", ['reason' => 'I am ill, sorry'])
            ->assertRedirect("/dashboard/bookings/{$booking->uid}");

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('host', $booking->cancelled_by);

        Mail::assertQueued(BookingCancelledGuest::class, fn ($mail) => str_contains($mail->render(), 'Jane Host cancelled your Intro call')
            && str_contains($mail->render(), 'I am ill, sorry'));
        Mail::assertNotQueued(BookingCancelledHost::class);
    }

    public function test_host_can_reschedule_and_only_the_guest_is_emailed(): void
    {
        $booking = $this->booking('2026-11-09 09:00');

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}/reschedule")->assertOk()->assertSee('Rescheduling');
        $slots = $this->actingAs($this->host)->getJson("/dashboard/bookings/{$booking->uid}/reschedule/slots?month=2026-11&timezone=Europe/Paris")->json('days.2026-11-09');
        $this->assertContains('2026-11-09T10:00:00+01:00', $slots, 'The booking\'s own time stays available while moving it.');

        $response = $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/reschedule", [
            'start' => '2026-11-10T15:00:00+01:00',
            'timezone' => 'Europe/Paris',
        ]);

        $new = Booking::where('rescheduled_from_id', $booking->id)->sole();
        $response->assertRedirect("/dashboard/bookings/{$new->uid}");
        $this->assertSame('2026-11-10 14:00:00', $new->start_at->format('Y-m-d H:i:s'));

        Mail::assertQueued(BookingRescheduledGuest::class, fn ($mail) => str_contains($mail->render(), 'Jane Host moved your Intro call'));
        Mail::assertNotQueued(BookingRescheduledHost::class);
    }

    public function test_a_failed_calendar_sync_can_be_retried(): void
    {
        Queue::fake();
        $booking = $this->booking('2026-11-09 09:00', ['sync_status' => SyncStatus::Failed]);

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")->assertSee('Try again');
        $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/sync")->assertSessionHas('status', 'sync-queued');

        Queue::assertPushed(SyncBookingToCalendar::class, fn ($job) => $job->booking->is($booking));
    }

    public function test_past_bookings_cannot_be_changed(): void
    {
        $booking = $this->booking('2026-11-04 09:00');

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")->assertSee('Completed')->assertDontSee('Cancel booking');
        $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/cancel")->assertSessionHas('error');
    }

    // --- Account deletion -----------------------------------------------

    public function test_accounts_with_bookings_cannot_be_deleted(): void
    {
        $this->booking('2026-11-09 09:00');

        $this->actingAs($this->host)->get('/profile')->assertSee('Accounts with booking history cannot be deleted yet');
        $this->actingAs($this->host)->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertNotNull($this->host->fresh());
    }

    public function test_the_last_admin_cannot_delete_their_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($admin->fresh());

        User::factory()->admin()->create();
        $this->actingAs($admin)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertNull($admin->fresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(string $startUtc, array $attributes = []): Booking
    {
        return Booking::factory()->at($this->eventType, $startUtc)->create($attributes + ['sync_status' => SyncStatus::Skipped]);
    }
}
