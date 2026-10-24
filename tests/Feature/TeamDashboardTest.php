<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\SchedulingType;
use App\Enums\TeamRole;
use App\Mail\BookingCancelledGuest;
use App\Mail\BookingCancelledHost;
use App\Mail\BookingRescheduledHost;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Hosts work Mon–Fri 09:00–17:00 UTC; "now" is 2026-11-01 10:00 UTC and
 * 2026-11-09 is a Monday. Alice organizes a collective "Panel" with Bob;
 * Carol is in the team but not a host; Tess is the team admin.
 */
class TeamDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $tess;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();

        $this->team = Team::factory()->create(['name' => 'Sales', 'slug' => 'sales']);
        [$this->alice, $this->bob, $this->carol, $this->tess] = collect(['Alice', 'Bob', 'Carol', 'Tess'])
            ->map(fn ($name) => User::factory()->create(['name' => $name, 'timezone' => 'UTC']))->all();
        $this->team->members()->attach([$this->alice->id, $this->bob->id, $this->carol->id], ['role' => TeamRole::Member]);
        $this->team->members()->attach($this->tess, ['role' => TeamRole::Admin]);

        $panel = EventType::factory()->forTeam($this->team, SchedulingType::Collective)->create(['title' => 'Panel']);
        $panel->hosts()->attach([$this->alice->id, $this->bob->id], ['is_fixed' => true]);

        [$this->booking] = app(BookingService::class)->book($panel, CarbonImmutable::parse('2026-11-09T10:00Z'), new GuestDetails('Grace Guest', 'grace@example.com', 'UTC'), CarbonImmutable::now());
        Mail::fake(); // Forget the confirmations.
    }

    public function test_co_hosts_see_the_booking_on_their_dashboard(): void
    {
        $this->actingAs($this->bob)->get('/dashboard')
            ->assertOk()->assertSee('Grace Guest')->assertSee('Panel · Sales')->assertSee('You have 1 upcoming booking.');

        $this->actingAs($this->bob)->get("/dashboard/bookings/{$this->booking->uid}")
            ->assertOk()->assertSee('Hosts')->assertSee('Organizer')->assertSee('Collective · Sales')
            ->assertSee('Alice has not connected Google Calendar');
    }

    public function test_team_members_who_do_not_attend_cannot_see_it(): void
    {
        $this->actingAs($this->carol)->get('/dashboard')->assertOk()->assertDontSee('Grace Guest');
        $this->actingAs($this->carol)->get("/dashboard/bookings/{$this->booking->uid}")->assertNotFound();
        $this->actingAs($this->carol)->post("/dashboard/bookings/{$this->booking->uid}/cancel")->assertNotFound();
        $this->actingAs($this->carol)->get('/teams/sales/bookings')->assertForbidden();
    }

    public function test_team_admins_see_every_team_booking_but_not_personal_ones(): void
    {
        $personal = Booking::factory()->at(EventType::factory()->for($this->alice)->create(), '2026-11-10T10:00Z')
            ->create(['guest_name' => 'Private Person']);

        $this->actingAs($this->tess)->get('/teams/sales/bookings')
            ->assertOk()->assertSee('Grace Guest')->assertDontSee('Private Person');
        $this->actingAs($this->tess)->get("/dashboard/bookings/{$this->booking->uid}")
            ->assertOk()->assertSee('Sales bookings');
        $this->actingAs($this->tess)->get("/dashboard/bookings/{$personal->uid}")->assertNotFound();

        // Not attending, so not on Tess's own dashboard.
        $this->actingAs($this->tess)->get('/dashboard')->assertOk()->assertDontSee('Grace Guest');

        // Global admins manage every team.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/teams/sales/bookings')->assertOk()->assertSee('Grace Guest');
        $this->actingAs($admin)->get("/dashboard/bookings/{$personal->uid}")->assertNotFound();
    }

    public function test_team_bookings_can_be_filtered_by_host_and_status(): void
    {
        $solo = EventType::factory()->forTeam($this->team)->create(['title' => 'Solo']);
        $solo->hosts()->attach($this->carol);
        Booking::factory()->at($solo, '2026-11-11T10:00Z')->create(['host_id' => $this->carol->id, 'guest_name' => 'Carol Guest']);
        Booking::factory()->at($solo, '2026-11-12T10:00Z')->create([
            'host_id' => $this->carol->id, 'guest_name' => 'Gone Guest', 'status' => BookingStatus::Cancelled, 'cancelled_by' => 'guest',
        ]);
        $solo->delete(); // Bookings of deleted event types stay listed.

        $this->actingAs($this->tess)->get("/teams/sales/bookings?host={$this->carol->id}")
            ->assertOk()->assertSee('Carol Guest')->assertDontSee('Grace Guest')->assertDontSee('Gone Guest');
        $this->actingAs($this->tess)->get("/teams/sales/bookings?host={$this->bob->id}")
            ->assertOk()->assertSee('Grace Guest')->assertDontSee('Carol Guest');
        $this->actingAs($this->tess)->get('/teams/sales/bookings?tab=cancelled')
            ->assertOk()->assertSee('Gone Guest')->assertSee('Cancelled by guest')->assertDontSee('Grace Guest');
    }

    public function test_a_team_admin_cancelling_tells_every_host(): void
    {
        $this->actingAs($this->tess)
            ->post("/dashboard/bookings/{$this->booking->uid}/cancel", ['reason' => 'Double booked'])
            ->assertRedirect("/dashboard/bookings/{$this->booking->uid}");

        $booking = $this->booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame($this->tess->id, $booking->cancelled_by_user_id);

        Mail::assertQueued(BookingCancelledHost::class, 2);
        Mail::assertQueued(BookingCancelledGuest::class, fn ($mail) => str_contains($mail->render(), 'Tess cancelled your Panel'));

        $this->actingAs($this->alice)->get('/dashboard?tab=cancelled')->assertSee('Cancelled by Tess');
        $this->actingAs($this->tess)->get("/dashboard/bookings/{$booking->uid}")->assertSee('Cancelled by you');
    }

    public function test_a_co_host_cancelling_is_not_emailed_about_it(): void
    {
        $this->actingAs($this->bob)->post("/dashboard/bookings/{$this->booking->uid}/cancel")->assertRedirect();

        Mail::assertQueued(BookingCancelledHost::class, 1);
        Mail::assertQueued(BookingCancelledHost::class, fn ($mail) => $mail->hasTo($this->alice->email));
        $this->actingAs($this->alice)->get("/dashboard/bookings/{$this->booking->uid}")->assertSee('Cancelled by Bob');
    }

    public function test_a_co_host_can_reschedule_from_the_dashboard(): void
    {
        $this->actingAs($this->bob)->get("/dashboard/bookings/{$this->booking->uid}/reschedule")
            ->assertOk()->assertSee('With Alice and Bob');
        $this->actingAs($this->bob)->getJson("/dashboard/bookings/{$this->booking->uid}/reschedule/slots?month=2026-11&timezone=UTC")
            ->assertOk()->assertJsonPath('days.2026-11-09.0', '2026-11-09T09:00:00+00:00');

        $this->actingAs($this->bob)
            ->post("/dashboard/bookings/{$this->booking->uid}/reschedule", ['start' => '2026-11-09T14:00:00Z', 'timezone' => 'UTC'])
            ->assertRedirect();

        $moved = Booking::where('status', BookingStatus::Confirmed)->sole();
        $this->assertSame('2026-11-09 14:00', $moved->start_at->format('Y-m-d H:i'));
        Mail::assertQueued(BookingRescheduledHost::class, 1);
        Mail::assertQueued(BookingRescheduledHost::class, fn ($mail) => $mail->hasTo($this->alice->email));
    }
}
