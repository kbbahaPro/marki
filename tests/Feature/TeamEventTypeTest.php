<?php

namespace Tests\Feature;

use App\Enums\SchedulingType;
use App\Enums\TeamRole;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $teamAdmin;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create(['name' => 'Sales', 'slug' => 'sales']);
        $this->teamAdmin = User::factory()->create(['name' => 'Ada Admin']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);

        $this->team->members()->attach($this->teamAdmin, ['role' => TeamRole::Admin]);
        $this->team->members()->attach([$this->alice->id, $this->bob->id], ['role' => TeamRole::Member]);
    }

    public function test_team_admin_can_create_a_round_robin_event_type(): void
    {
        $this->actingAs($this->teamAdmin)->get('/teams/sales/event-types/create')
            ->assertOk()->assertSee('Round robin')->assertSee('Alice')->assertSee('team/sales/');

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'questions' => [['label' => 'Company', 'type' => 'text', 'is_required' => '1']],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $eventType = $this->team->eventTypes()->sole();
        $this->assertNull($eventType->user_id);
        $this->assertSame(SchedulingType::RoundRobin, $eventType->scheduling_type);
        $this->assertSame(url('team/sales/demo'), $eventType->publicUrl());
        $this->assertSame('Company', $eventType->questions->sole()->label);
        $this->assertFalse($this->teamAdmin->eventTypes()->exists());

        $hosts = $eventType->hosts->keyBy('name');
        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], $hosts->keys()->all());
        $this->assertTrue($hosts['Alice']->pivot->is_fixed);
        $this->assertFalse($hosts['Bob']->pivot->is_fixed);
        $this->assertSame(4, $hosts['Bob']->pivot->priority);
        $this->assertSame(150, $hosts['Bob']->pivot->weight);

        $this->actingAs($this->teamAdmin)->get('/event-types')
            ->assertOk()->assertSee('Team event types')->assertSee('Demo')->assertSee('/team/sales/demo')
            ->assertSee("/teams/sales/event-types/{$eventType->id}/edit");
        $this->actingAs($this->teamAdmin)->get("/teams/sales/event-types/{$eventType->id}/edit")
            ->assertOk()->assertSee('Round robin event for Sales');
    }

    public function test_collective_hosts_all_attend_and_need_at_least_two(): void
    {
        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'scheduling_type' => 'collective',
                'hosts' => [['user_id' => $this->alice->id, 'is_fixed' => '0', 'priority' => '4', 'weight' => '10']],
            ]))
            ->assertSessionHasErrors(['hosts' => 'A collective event needs at least two hosts.']);

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'scheduling_type' => 'collective',
                'hosts' => [
                    ['user_id' => $this->alice->id, 'is_fixed' => '0', 'priority' => '4', 'weight' => '10'],
                    ['user_id' => $this->bob->id, 'is_fixed' => '0', 'priority' => '0', 'weight' => '500'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $hosts = $this->team->eventTypes()->sole()->hosts;
        $this->assertCount(2, $hosts);
        foreach ($hosts as $host) {
            $this->assertTrue($host->pivot->is_fixed);
            $this->assertSame(2, $host->pivot->priority);
            $this->assertSame(100, $host->pivot->weight);
        }
    }

    public function test_round_robin_needs_a_host_who_takes_turns(): void
    {
        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'hosts' => [['user_id' => $this->alice->id, 'is_fixed' => '1', 'priority' => '2', 'weight' => '100']],
            ]))
            ->assertSessionHasErrors('hosts');

        $this->assertFalse(EventType::exists());
    }

    public function test_hosts_must_be_team_members_and_settings_in_range(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'hosts' => [['user_id' => $outsider->id, 'is_fixed' => '0', 'priority' => '2', 'weight' => '100']],
            ]))
            ->assertSessionHasErrors(['hosts.0.user_id' => 'Hosts must be members of the team.']);

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm([
                'hosts' => [['user_id' => $this->bob->id, 'is_fixed' => '0', 'priority' => '5', 'weight' => '0']],
            ]))
            ->assertSessionHasErrors(['hosts.0.priority', 'hosts.0.weight']);

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm(['hosts' => []]))
            ->assertSessionHasErrors('hosts');

        $this->actingAs($this->teamAdmin)
            ->post('/teams/sales/event-types', $this->validForm(['scheduling_type' => 'single']))
            ->assertSessionHasErrors('scheduling_type');

        $this->assertFalse(EventType::exists());
    }

    public function test_slugs_are_unique_per_team_only(): void
    {
        EventType::factory()->forTeam($this->team)->create(['slug' => 'demo']);
        EventType::factory()->for($this->teamAdmin)->create(['slug' => 'intro']);
        $other = Team::factory()->create();
        EventType::factory()->forTeam($other)->create(['slug' => 'intro']);

        $this->actingAs($this->teamAdmin)->post('/teams/sales/event-types', $this->validForm())->assertSessionHasErrors('slug');
        $this->actingAs($this->teamAdmin)->post('/teams/sales/event-types', $this->validForm(['slug' => 'intro']))->assertSessionHasNoErrors();
    }

    public function test_hosts_and_settings_are_updated(): void
    {
        $eventType = EventType::factory()->forTeam($this->team)->create(['slug' => 'demo']);
        $eventType->hosts()->attach([$this->alice->id => ['priority' => 2], $this->bob->id => ['priority' => 2]]);

        $this->actingAs($this->teamAdmin)
            ->put("/teams/sales/event-types/{$eventType->id}", $this->validForm([
                'title' => 'Discovery',
                'hosts' => [['user_id' => $this->bob->id, 'is_fixed' => '0', 'priority' => '0', 'weight' => '50']],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/teams/sales/event-types/{$eventType->id}/edit");

        $eventType->refresh();
        $this->assertSame('Discovery', $eventType->title);
        $bob = $eventType->hosts->sole();
        $this->assertTrue($bob->is($this->bob));
        $this->assertSame(0, $bob->pivot->priority);
        $this->assertSame(50, $bob->pivot->weight);
    }

    public function test_only_team_and_global_admins_manage_team_event_types(): void
    {
        $eventType = EventType::factory()->forTeam($this->team)->create(['title' => 'Team demo']);

        // Members see the event type but cannot edit it.
        $this->actingAs($this->alice)->get('/event-types')
            ->assertOk()->assertSee('Team demo')
            ->assertDontSee("/teams/sales/event-types/{$eventType->id}/edit")
            ->assertDontSee('New team event type');
        $this->actingAs($this->alice)->get('/teams/sales/event-types/create')->assertForbidden();
        $this->actingAs($this->alice)->post('/teams/sales/event-types', $this->validForm())->assertForbidden();
        $this->actingAs($this->alice)->get("/teams/sales/event-types/{$eventType->id}/edit")->assertForbidden();
        $this->actingAs($this->alice)->delete("/teams/sales/event-types/{$eventType->id}")->assertForbidden();

        // Outsiders do not see the team at all.
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get('/event-types')->assertOk()->assertDontSee('Team demo');

        // Global admins manage every team, member or not.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/event-types')->assertOk()->assertSee("/teams/sales/event-types/{$eventType->id}/edit");
        $this->actingAs($admin)->get("/teams/sales/event-types/{$eventType->id}/edit")->assertOk();

        $this->assertNotNull($eventType->fresh());
    }

    public function test_event_types_are_scoped_to_their_team(): void
    {
        $other = Team::factory()->create(['slug' => 'support']);
        $other->members()->attach($this->teamAdmin, ['role' => TeamRole::Admin]);
        $foreign = EventType::factory()->forTeam($other)->create();
        $personal = EventType::factory()->for($this->teamAdmin)->create();
        $teamType = EventType::factory()->forTeam($this->team)->create();

        $this->actingAs($this->teamAdmin)->get("/teams/sales/event-types/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->teamAdmin)->get("/teams/sales/event-types/{$personal->id}/edit")->assertNotFound();
        // Team event types are not editable through the personal routes.
        $this->actingAs($this->teamAdmin)->get("/event-types/{$teamType->id}/edit")->assertNotFound();
    }

    public function test_deleting_a_team_event_type(): void
    {
        $eventType = EventType::factory()->forTeam($this->team)->create();

        $this->actingAs($this->teamAdmin)
            ->delete("/teams/sales/event-types/{$eventType->id}")
            ->assertRedirect('/event-types');

        $this->assertSoftDeleted($eventType);
    }

    public function test_a_team_with_event_types_or_booking_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $eventType = EventType::factory()->forTeam($this->team)->create();

        $this->actingAs($admin)->get('/admin/teams/sales')->assertSee('Delete the team’s event types first.');
        $this->actingAs($admin)->delete('/admin/teams/sales')->assertSessionHasErrors('team');
        $this->assertModelExists($this->team);

        Booking::factory()->create(['event_type_id' => $eventType->id, 'host_id' => $this->alice->id]);
        $eventType->delete();

        $this->actingAs($admin)->delete('/admin/teams/sales')->assertSessionHasErrors('team');
        $this->assertModelExists($this->team);
    }

    public function test_deleting_a_team_removes_its_deleted_event_types_without_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        $eventType = EventType::factory()->forTeam($this->team)->create();
        $eventType->hosts()->attach($this->alice);
        $eventType->delete();

        $this->actingAs($admin)->delete('/admin/teams/sales')->assertRedirect('/admin/teams');

        $this->assertModelMissing($this->team);
        $this->assertFalse(EventType::withTrashed()->exists());
    }

    public function test_removing_a_member_removes_them_as_host(): void
    {
        $admin = User::factory()->admin()->create();
        $eventType = EventType::factory()->forTeam($this->team)->create();
        $eventType->hosts()->attach([$this->alice->id, $this->bob->id]);
        $personal = EventType::factory()->for($this->teamAdmin)->create();

        $this->actingAs($admin)->delete("/admin/teams/sales/members/{$this->alice->id}")->assertRedirect();

        $this->assertSame([$this->bob->id], $eventType->hosts()->pluck('users.id')->all());
        $this->assertModelExists($personal);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Demo',
            'slug' => 'demo',
            'description' => null,
            'duration_minutes' => 30,
            'slot_interval_minutes' => null,
            'location_type' => 'google_meet',
            'location_value' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'min_notice_minutes' => 60,
            'max_days_ahead' => 30,
            'daily_limit' => 4,
            'reminder_offsets' => [60],
            'is_active' => '1',
            'scheduling_type' => 'round_robin',
            'hosts' => [
                ['user_id' => $this->alice->id, 'is_fixed' => '1', 'priority' => '2', 'weight' => '100'],
                ['user_id' => $this->bob->id, 'is_fixed' => '0', 'priority' => '4', 'weight' => '150'],
            ],
        ], $overrides);
    }
}
