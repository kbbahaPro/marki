<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\RendersBookingCalendar;
use App\Models\EventType;
use App\Models\Team;
use App\Services\Scheduling\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A team's public page and its round robin and collective booking pages.
 * Bookings are submitted to BookingPageController::store.
 */
class TeamPageController extends Controller
{
    use RendersBookingCalendar;

    public function show(Team $team): View
    {
        return view('public.team', [
            'team' => $team->load('members'),
            'eventTypes' => $team->eventTypes()->where('is_active', true)->has('hosts')->orderBy('title')->get(),
        ]);
    }

    public function book(Team $team, string $slug): View
    {
        $eventType = $this->eventType($team, $slug);

        return view('public.book', [
            'eventType' => $eventType,
            'host' => null,
            'config' => $this->calendarConfig($eventType, route('public.team.slots', [$team, $slug])),
            'formAction' => route('public.team.book.store', [$team, $slug]),
            'reschedule' => null,
        ]);
    }

    public function slots(Request $request, AvailabilityService $availability, Team $team, string $slug): JsonResponse
    {
        return $this->slotsResponse($request, $availability, $this->eventType($team, $slug));
    }

    private function eventType(Team $team, string $slug): EventType
    {
        return $team->eventTypes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['hosts', 'questions'])
            ->firstOrFail();
    }
}
