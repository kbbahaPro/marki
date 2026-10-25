<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Every booking of a team's event types, for team admins and global admins.
 * Members see the team bookings they attend on their own dashboard instead.
 */
class TeamBookingController extends Controller
{
    public function index(Request $request, Team $team): View
    {
        abort_unless($team->isManagedBy($request->user()), 403);

        $tab = in_array($request->query('tab'), DashboardController::TABS, true) ? $request->query('tab') : 'upcoming';
        $team->load('members');
        $hostId = $team->members->contains('id', (int) $request->query('host')) ? (int) $request->query('host') : null;

        // Deleted event types still own their past bookings.
        $query = Booking::query()
            ->whereIn('event_type_id', $team->eventTypes()->withTrashed()->select('id'))
            ->when($hostId, fn ($q) => $q->attendedBy($hostId))
            ->with(['eventType.team', 'host', 'hosts', 'cancelledBy']);

        match ($tab) {
            'upcoming' => $query->confirmed()->where('end_at', '>', now())->orderBy('start_at'),
            'past' => $query->kept()->where('end_at', '<=', now())->orderByDesc('start_at'),
            'cancelled' => $query->where('status', BookingStatus::Cancelled)->orderByDesc('start_at'),
        };

        $bookings = $query->paginate(20)->withQueryString();
        $tz = $request->user()->timezone;

        return view('teams.bookings', [
            'team' => $team,
            'tab' => $tab,
            'hostId' => $hostId,
            'bookings' => $bookings,
            'tz' => $tz,
            'days' => $bookings->getCollection()->groupBy(fn (Booking $b) => $b->start_at->setTimezone($tz)->toDateString()),
        ]);
    }
}
