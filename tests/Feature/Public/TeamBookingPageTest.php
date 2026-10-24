<?php

namespace Tests\Feature\Public;

use App\Enums\BookingStatus;
use App\Enums\SchedulingType;
use App\Mail\BookingCancelledHost;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Hosts work Mon–Fri 09:00–17:00 UTC; "now" is 2026-11-01 10:00 UTC and
 * 2026-11-09 is a Monday.
 */
class TeamBookingPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $alice;

    private User $bob;

    private EventType $demo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();

        $this->team = Team::factory()->create(['name' => 'Sales', 'slug' => 'sales']);
        $this->alice = User::factory()->create(['name' => 'Alice', 'timezone' => 'UTC']);
        $this->bob = User::factory()->create(['name' => 'Bob', 'timezone' => 'UTC']);
        $this->team->members()->attach([$this->alice->id, $this->bob->id], ['role' => 'member']);

        $this->demo = EventType::factory()->forTeam($this->team)->create(['title' => 'Product demo', 'slug' => 'demo']);
        $this->demo->hosts()->attach([$this->alice->id, $this->bob->id]);
    }

    public function test_the_team_page_lists_bookable_event_types(): void
    {
        EventType::factory()->forTeam($this->team)->create(['title' => 'Switched off', 'is_active' => false])
            ->hosts()->attach($this->alice);
        EventType::factory()->forTeam($this->team)->create(['title' => 'Nobody hosts']);

        $this->get('/team/sales')
            ->assertOk()
            ->assertSee('Product demo')
            ->assertSee(url('team/sales/demo'))
            ->assertDontSee('Switched off')
            ->assertDontSee('Nobody hosts');
    }

    public function test_the_booking_page_shows_the_team_and_its_hosts(): void
    {
        $this->get('/team/sales/demo')
            ->assertOk()
            ->assertSee('Product demo')
            ->assertSee('Sales')
            ->assertSee('With one of Alice or Bob');

        $collective = EventType::factory()->forTeam($this->team, SchedulingType::Collective)->create(['slug' => 'panel']);
        $collective->hosts()->attach([$this->alice->id, $this->bob->id], ['is_fixed' => true]);
        $this->get('/team/sales/panel')->assertOk()->assertSee('With Alice and Bob');

        $carol = User::factory()->create(['name' => 'Carol']);
        $this->team->members()->attach($carol, ['role' => 'member']);
        $this->demo->hosts()->attach($carol, ['is_fixed' => true]);
        $this->get('/team/sales/demo')->assertOk()->assertSee('With Carol and one of Alice or Bob');

        $this->demo->hosts()->detach($this->bob);
        $this->get('/team/sales/demo')->assertOk()->assertSee('With Alice and Carol');
    }

    public function test_unknown_inactive_or_other_teams_event_types_are_not_found(): void
    {
        $other = Team::factory()->create(['slug' => 'support']);
        EventType::factory()->forTeam($other)->create(['slug' => 'help']);
        EventType::factory()->forTeam($this->team)->create(['slug' => 'off', 'is_active' => false]);

        $this->get('/team/sales/nope')->assertNotFound();
        $this->get('/team/sales/off')->assertNotFound();
        $this->get('/team/sales/help')->assertNotFound();
        $this->get('/team/nope/demo')->assertNotFound();
        $this->post('/team/sales/help', $this->form())->assertNotFound();
    }

    public function test_slots_are_served_for_team_event_types(): void
    {
        $days = $this->getJson('/team/sales/demo/slots?month=2026-11&timezone=UTC')->assertOk()->json('days');

        $this->assertCount(16, $days['2026-11-09']);
        $this->assertSame('2026-11-09T09:00:00+00:00', $days['2026-11-09'][0]);
    }

    public function test_a_guest_books_a_round_robin_event(): void
    {
        $question = $this->demo->questions()->create(['label' => 'Company', 'type' => 'text', 'is_required' => true, 'position' => 0]);

        $this->post('/team/sales/demo', $this->form())->assertSessionHasErrors("answers.{$question->id}");

        $response = $this->post('/team/sales/demo', $this->form(['answers' => [$question->id => 'Acme']]));

        $booking = Booking::sole();
        $response->assertRedirect("/bookings/{$booking->uid}");
        $this->assertTrue($booking->host->is($this->alice));
        $this->assertSame('Acme', $booking->answers->sole()->value);

        $this->get("/bookings/{$booking->uid}")->assertOk()->assertSee('Product demo')->assertSee('with Alice');
    }

    public function test_a_taken_slot_sends_the_guest_back(): void
    {
        $this->post('/team/sales/demo', $this->form())->assertRedirect();
        $this->post('/team/sales/demo', $this->form(['email' => 'second@example.com']))->assertRedirect();

        $this->post('/team/sales/demo', $this->form(['email' => 'third@example.com']))
            ->assertSessionHasErrors('start');

        $this->assertEqualsCanonicalizing([$this->alice->id, $this->bob->id], Booking::pluck('host_id')->all());
    }

    public function test_guests_can_reschedule_and_cancel_a_collective_booking(): void
    {
        $panel = EventType::factory()->forTeam($this->team, SchedulingType::Collective)->create(['title' => 'Panel', 'slug' => 'panel']);
        $panel->hosts()->attach([$this->alice->id, $this->bob->id], ['is_fixed' => true]);

        $this->post('/team/sales/panel', $this->form())->assertRedirect();
        $token = session('manage_token');

        $this->get("/manage/{$token}")->assertOk()->assertSee('Panel with Alice and Bob');
        $this->get("/manage/{$token}/reschedule")->assertOk()->assertSee('Sales')->assertSee('With Alice and Bob');

        // The booking's own time is offered again when moving it.
        $days = $this->getJson("/manage/{$token}/reschedule/slots?month=2026-11&timezone=UTC")->json('days');
        $this->assertContains('2026-11-09T09:00:00+00:00', $days['2026-11-09']);

        $this->post("/manage/{$token}/reschedule", ['start' => '2026-11-09T11:00:00Z', 'timezone' => 'UTC'])->assertRedirect();
        $moved = Booking::where('status', BookingStatus::Confirmed)->sole();
        $this->assertSame(['Alice', 'Bob'], $moved->hosts->pluck('name')->all());
        $newToken = session('manage_token');

        $this->post("/manage/{$newToken}/cancel", ['reason' => 'Clash'])->assertRedirect("/manage/{$newToken}");
        $this->assertSame(BookingStatus::Cancelled, $moved->fresh()->status);
        Mail::assertQueued(BookingCancelledHost::class, 2);

        $this->get("/manage/{$newToken}")->assertOk()
            ->assertSee('We have let Alice and Bob know')
            ->assertSee(url('team/sales/panel'));
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
