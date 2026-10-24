<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_can_create_a_team(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/teams', ['name' => 'Sales', 'slug' => 'sales', 'description' => 'Demos and onboarding'])
            ->assertRedirect('/admin/teams/sales')
            ->assertSessionHas('status', 'team-created');

        $team = Team::sole();
        $this->assertSame('Sales', $team->name);
        $this->assertSame('Demos and onboarding', $team->description);

        $this->actingAs($this->admin)->get('/admin/teams')->assertOk()->assertSee('Sales')->assertSee('/team/sales');
        $this->actingAs($this->admin)->get('/admin/teams/sales')->assertOk()->assertSee('Members');
    }

    public function test_team_slugs_must_be_url_safe_and_unique(): void
    {
        Team::factory()->create(['slug' => 'sales']);

        $this->actingAs($this->admin)->post('/admin/teams', ['name' => 'Sales 2', 'slug' => 'sales'])->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->post('/admin/teams', ['name' => 'Bad', 'slug' => 'Bad Slug!'])->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->post('/admin/teams', ['name' => '', 'slug' => 'ok'])->assertSessionHasErrors('name');

        $this->assertSame(1, Team::count());
    }

    public function test_admin_can_update_and_delete_a_team(): void
    {
        $team = Team::factory()->create(['name' => 'Sales', 'slug' => 'sales']);
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member]);

        $this->actingAs($this->admin)
            ->put('/admin/teams/sales', ['name' => 'Sales EMEA', 'slug' => 'sales-emea'])
            ->assertRedirect('/admin/teams/sales-emea');
        $this->assertSame('Sales EMEA', $team->fresh()->name);

        $this->actingAs($this->admin)->delete('/admin/teams/sales-emea')->assertRedirect('/admin/teams');

        $this->assertModelMissing($team);
        $this->assertNotNull($member->fresh(), 'Members keep their accounts.');
        $this->assertDatabaseCount('team_members', 0);
    }

    public function test_admin_manages_membership_and_roles(): void
    {
        $team = Team::factory()->create(['slug' => 'sales']);
        $ana = User::factory()->create(['name' => 'Ana']);

        $this->actingAs($this->admin)
            ->post('/admin/teams/sales/members', ['user_id' => $ana->id, 'role' => 'member'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($team->hasMember($ana));
        $this->assertSame(TeamRole::Member, $team->members()->first()->pivot->role);

        // Adding twice is refused with a friendly message.
        $this->actingAs($this->admin)
            ->post('/admin/teams/sales/members', ['user_id' => $ana->id, 'role' => 'member'])
            ->assertSessionHasErrors('user_id');

        $this->actingAs($this->admin)->patch("/admin/teams/sales/members/{$ana->id}", ['role' => 'admin']);
        $this->assertTrue($team->isManagedBy($ana));

        $this->actingAs($this->admin)->delete("/admin/teams/sales/members/{$ana->id}");
        $this->assertFalse($team->hasMember($ana));
        $this->assertNotNull($ana->fresh());
    }

    public function test_roles_and_users_are_validated(): void
    {
        Team::factory()->create(['slug' => 'sales']);
        $outsider = User::factory()->create();

        $this->actingAs($this->admin)->post('/admin/teams/sales/members', ['user_id' => 9999, 'role' => 'member'])->assertSessionHasErrors('user_id');
        $this->actingAs($this->admin)->post('/admin/teams/sales/members', ['user_id' => $outsider->id, 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->patch("/admin/teams/sales/members/{$outsider->id}", ['role' => 'admin'])->assertNotFound();
    }

    public function test_a_user_can_belong_to_several_teams(): void
    {
        $user = User::factory()->create();
        $sales = Team::factory()->create(['name' => 'Sales']);
        $support = Team::factory()->create(['name' => 'Support']);
        $sales->members()->attach($user, ['role' => TeamRole::Admin]);
        $support->members()->attach($user, ['role' => TeamRole::Member]);

        $this->assertSame(['Sales', 'Support'], $user->teams->pluck('name')->all());
        $this->assertTrue($sales->isManagedBy($user));
        $this->assertFalse($support->isManagedBy($user));
        $this->assertTrue($support->isManagedBy($this->admin), 'Global admins manage every team.');
    }

    public function test_only_global_admins_can_manage_teams(): void
    {
        $team = Team::factory()->create(['slug' => 'sales']);
        $teamAdmin = User::factory()->create();
        $team->members()->attach($teamAdmin, ['role' => TeamRole::Admin]);

        $this->get('/admin/teams')->assertRedirect('/login');

        foreach ([$teamAdmin, User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/admin/teams')->assertForbidden();
            $this->actingAs($user)->post('/admin/teams', ['name' => 'X', 'slug' => 'x'])->assertForbidden();
            $this->actingAs($user)->delete('/admin/teams/sales')->assertForbidden();
        }
    }

    public function test_public_team_page_shows_members_and_escapes_content(): void
    {
        $team = Team::factory()->create(['name' => 'Sales <b>', 'slug' => 'sales', 'description' => '<script>x</script>']);
        $team->members()->attach(User::factory()->create(['name' => 'Ana Lopez']), ['role' => TeamRole::Member]);

        $this->get('/team/sales')
            ->assertOk()
            ->assertSee('Sales &lt;b&gt;', escape: false)
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertSee('AL');

        $this->get('/team/does-not-exist')->assertNotFound();
    }

    public function test_navigation_shows_members_and_teams_to_admins_only(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee(route('members.index'))->assertSee(route('admin.teams.index'));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertDontSee(route('admin.teams.index'));
    }
}
