<?php

namespace Tests\Feature\Public;

use App\Enums\SchedulingType;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ?name=&email= on a booking page fills in the form, e.g. from a CRM's
 * "Book a meeting" link.
 */
class BookingPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        $host = User::factory()->create(['username' => 'jane']);
        EventType::factory()->for($host)->create(['slug' => 'intro']);
    }

    public function test_name_and_email_are_prefilled(): void
    {
        $this->get('/jane/intro?name=Jane%20Doe&email=jane%40acme.example')
            ->assertOk()
            ->assertSee('value="Jane Doe"', false)
            ->assertSee('value="jane@acme.example"', false);
    }

    public function test_prefilled_values_are_escaped(): void
    {
        $this->get('/jane/intro?name='.urlencode('"><script>x</script>'))
            ->assertOk()
            ->assertDontSee('<script>x</script>', false);
    }

    public function test_unusable_values_are_ignored(): void
    {
        $this->get('/jane/intro?name[]=x&email=not-an-email')
            ->assertOk()
            ->assertDontSee('value="not-an-email"', false);
    }

    public function test_team_booking_pages_are_prefilled_too(): void
    {
        $team = Team::factory()->create(['slug' => 'sales']);
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => 'member']);
        $eventType = EventType::factory()->forTeam($team, SchedulingType::RoundRobin)->create(['slug' => 'demo']);
        $eventType->hosts()->attach($member);

        $this->get('/team/sales/demo?name=Jane%20Doe&email=jane%40acme.example')
            ->assertOk()
            ->assertSee('value="Jane Doe"', false)
            ->assertSee('value="jane@acme.example"', false);
    }
}
