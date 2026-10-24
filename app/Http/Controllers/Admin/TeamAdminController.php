<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Global admins create teams and manage who is in them.
 */
class TeamAdminController extends Controller
{
    private const SLUG_RULE = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function index(): View
    {
        return view('admin.teams.index', [
            'teams' => Team::query()->withCount('members')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $team = Team::create($validated);

        return redirect()->route('admin.teams.edit', $team)->with('status', 'team-created');
    }

    public function edit(Team $team): View
    {
        $team->load('members');

        return view('admin.teams.edit', [
            'team' => $team,
            'candidates' => User::query()->whereNotIn('id', $team->members->modelKeys())->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $team->update($request->validate($this->rules($team)));

        return redirect()->route('admin.teams.edit', $team)->with('status', 'team-updated');
    }

    public function destroy(Team $team): RedirectResponse
    {
        $team->delete();

        return redirect()->route('admin.teams.index')->with('status', 'team-deleted');
    }

    public function addMember(Request $request, Team $team): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists(User::class, 'id'), Rule::unique('team_members')->where('team_id', $team->id)],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ], ['user_id.unique' => __('That person is already in the team.')]);

        $team->members()->attach($validated['user_id'], ['role' => $validated['role']]);

        return back()->with('status', 'member-added');
    }

    public function updateMember(Request $request, Team $team, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        abort_unless($team->hasMember($user), 404);
        $team->members()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return back()->with('status', 'member-updated');
    }

    public function removeMember(Team $team, User $user): RedirectResponse
    {
        abort_unless($team->hasMember($user), 404);
        $team->members()->detach($user->id);

        return back()->with('status', 'member-removed');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Team $team = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', self::SLUG_RULE, Rule::unique(Team::class)->ignore($team?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
