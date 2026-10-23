<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Http\Requests\EventTypeRequest;
use App\Models\EventType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventTypeController extends Controller
{
    public function index(Request $request): View
    {
        return view('event-types.index', [
            'eventTypes' => $request->user()->eventTypes()->orderBy('title')->get(),
        ]);
    }

    public function create(): View
    {
        $defaults = config('booking.event_type_defaults');

        return view('event-types.create', [
            'eventType' => new EventType($defaults + [
                'location_type' => LocationType::GoogleMeet,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(EventTypeRequest $request): RedirectResponse
    {
        $eventType = DB::transaction(function () use ($request) {
            $eventType = $request->user()->eventTypes()->create($request->eventTypeAttributes());
            $this->syncQuestions($eventType, $request->questions());

            return $eventType;
        });

        return redirect()->route('event-types.edit', $eventType)->with('status', 'event-type-created');
    }

    public function edit(Request $request, EventType $eventType): View
    {
        $this->authorizeOwner($request, $eventType);

        return view('event-types.edit', ['eventType' => $eventType->load('questions')]);
    }

    public function update(EventTypeRequest $request, EventType $eventType): RedirectResponse
    {
        $this->authorizeOwner($request, $eventType);

        DB::transaction(function () use ($request, $eventType) {
            $eventType->update($request->eventTypeAttributes());
            $this->syncQuestions($eventType, $request->questions());
        });

        return redirect()->route('event-types.edit', $eventType)->with('status', 'event-type-updated');
    }

    public function destroy(Request $request, EventType $eventType): RedirectResponse
    {
        $this->authorizeOwner($request, $eventType);

        $eventType->delete();

        return redirect()->route('event-types.index')->with('status', 'event-type-deleted');
    }

    /**
     * Update questions in place by id, create new ones and delete removed ones.
     * Existing booking answers keep a copy of the label, so deleting is safe.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    private function syncQuestions(EventType $eventType, array $questions): void
    {
        $keep = [];

        foreach ($questions as $data) {
            $attributes = collect($data)->except('id')->all();
            // Scoped lookup: an id from another event type is treated as a new question.
            $question = $data['id'] ? $eventType->questions()->find($data['id']) : null;

            if ($question) {
                $question->update($attributes);
            } else {
                $question = $eventType->questions()->create($attributes);
            }

            $keep[] = $question->id;
        }

        $eventType->questions()->whereNotIn('id', $keep)->delete();
    }

    private function authorizeOwner(Request $request, EventType $eventType): void
    {
        // 404 rather than 403 so ids of other members' event types are not confirmed.
        abort_unless($eventType->user_id === $request->user()->id, 404);
    }
}
