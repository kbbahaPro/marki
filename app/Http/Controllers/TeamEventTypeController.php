<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Enums\SchedulingType;
use App\Http\Requests\TeamEventTypeRequest;
use App\Models\EventType;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Team admins (and global admins) manage a team's round robin and collective event types.
 */
class TeamEventTypeController extends Controller
{
    public function create(Request $request, Team $team): View
    {
        $this->authorizeManager($request, $team);

        $defaults = config('booking.event_type_defaults');

        return view('event-types.create', [
            'team' => $team->load('members'),
            'eventType' => new EventType($defaults + [
                'scheduling_type' => SchedulingType::RoundRobin,
                'location_type' => LocationType::GoogleMeet,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(TeamEventTypeRequest $request, Team $team): RedirectResponse
    {
        $this->authorizeManager($request, $team);

        $eventType = DB::transaction(function () use ($request, $team) {
            $eventType = $team->eventTypes()->create($request->eventTypeAttributes());
            $eventType->hosts()->sync($request->hosts());
            $eventType->syncQuestions($request->questions());

            return $eventType;
        });

        return redirect()->route('teams.event-types.edit', [$team, $eventType])->with('status', 'event-type-created');
    }

    public function edit(Request $request, Team $team, EventType $eventType): View
    {
        $this->authorizeManager($request, $team);

        return view('event-types.edit', [
            'team' => $team->load('members'),
            'eventType' => $eventType->load(['questions', 'hosts']),
        ]);
    }

    public function update(TeamEventTypeRequest $request, Team $team, EventType $eventType): RedirectResponse
    {
        $this->authorizeManager($request, $team);

        DB::transaction(function () use ($request, $eventType) {
            $eventType->update($request->eventTypeAttributes());
            $eventType->hosts()->sync($request->hosts());
            $eventType->syncQuestions($request->questions());
        });

        return redirect()->route('teams.event-types.edit', [$team, $eventType])->with('status', 'event-type-updated');
    }

    public function destroy(Request $request, Team $team, EventType $eventType): RedirectResponse
    {
        $this->authorizeManager($request, $team);

        $eventType->delete();

        return redirect()->route('event-types.index')->with('status', 'event-type-deleted');
    }

    private function authorizeManager(Request $request, Team $team): void
    {
        abort_unless($team->isManagedBy($request->user()), 403);
    }
}
