<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Exceptions\BookingNotChangeableException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Public\Concerns\RendersBookingCalendar;
use App\Jobs\SyncBookingToCalendar;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The bookings a person hosts or co-hosts: lists, details, and host-side
 * cancel/reschedule. Team admins can also open their team's bookings here
 * (listed on the team Bookings page).
 */
class DashboardController extends Controller
{
    use RendersBookingCalendar;

    public const TABS = ['upcoming', 'past', 'cancelled'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'upcoming';

        // "Rescheduled" rows are history; the booking they moved to is listed instead.
        $query = $this->attended($user)->with(['eventType.team', 'hosts', 'host', 'cancelledBy']);

        match ($tab) {
            'upcoming' => $query->confirmed()->where('end_at', '>', now())->orderBy('start_at'),
            'past' => $query->confirmed()->where('end_at', '<=', now())->orderByDesc('start_at'),
            'cancelled' => $query->where('status', BookingStatus::Cancelled)->orderByDesc('start_at'),
        };

        $bookings = $query->paginate(20)->withQueryString();

        return view('dashboard.index', [
            'tab' => $tab,
            'bookings' => $bookings,
            // Upcoming bookings read best grouped by day in the host's zone.
            'days' => $bookings->getCollection()->groupBy(fn (Booking $b) => $b->start_at->setTimezone($user->timezone)->toDateString()),
            'counts' => [
                'upcoming' => $this->attended($user)->confirmed()->where('end_at', '>', now())->count(),
            ],
            'next' => $this->attended($user)->confirmed()->where('end_at', '>', now())->orderBy('start_at')->with('eventType')->first(),
            'eventTypeCount' => $user->eventTypes()->where('is_active', true)->count(),
            'connection' => $user->googleConnection,
        ]);
    }

    public function show(Request $request, Booking $booking): View
    {
        $this->authorizeHost($request, $booking);

        return view('dashboard.booking', [
            'booking' => $booking->load(['eventType.team', 'host', 'hosts', 'cancelledBy', 'answers', 'rescheduledFrom', 'rescheduledTo']),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function cancel(Request $request, BookingService $bookings, Booking $booking): RedirectResponse
    {
        $this->authorizeHost($request, $booking);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $bookings->cancel($booking, 'host', $validated['reason'] ?? null, $request->user());
        } catch (BookingNotChangeableException) {
            return back()->with('error', __('This booking can no longer be cancelled.'));
        }

        return redirect()->route('dashboard.bookings.show', $booking)->with('status', 'booking-cancelled');
    }

    public function editTime(Request $request, Booking $booking): View|RedirectResponse
    {
        $this->authorizeHost($request, $booking);

        if (! $booking->isChangeable() || ! $booking->eventType->is_active || $booking->eventType->trashed()) {
            return redirect()->route('dashboard.bookings.show', $booking)
                ->with('error', __('This booking can no longer be rescheduled.'));
        }

        $eventType = $booking->eventType->load(['user', 'team', 'hosts', 'questions']);

        return view('public.book', [
            'eventType' => $eventType,
            'host' => $eventType->user, // null for team event types
            'config' => $this->calendarConfig($eventType, route('dashboard.bookings.reschedule.slots', $booking), $request->user()->timezone),
            'formAction' => route('dashboard.bookings.reschedule.store', $booking),
            'reschedule' => $booking,
        ]);
    }

    public function slots(Request $request, AvailabilityService $availability, Booking $booking): JsonResponse
    {
        $this->authorizeHost($request, $booking);

        return $this->slotsResponse($request, $availability, $booking->eventType, ignoring: $booking);
    }

    public function reschedule(Request $request, BookingService $bookings, Booking $booking): RedirectResponse
    {
        $this->authorizeHost($request, $booking);
        $validated = $request->validate([
            'start' => ['required', 'string', 'date'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        try {
            [$newBooking] = $bookings->reschedule($booking, CarbonImmutable::parse($validated['start']), by: 'host', actor: $request->user());
        } catch (BookingNotChangeableException) {
            return redirect()->route('dashboard.bookings.show', $booking)
                ->with('error', __('This booking can no longer be rescheduled.'));
        } catch (SlotUnavailableException) {
            return back()->withInput()
                ->withErrors(['start' => __('Sorry, that time is no longer available. Please pick another one.')]);
        }

        return redirect()->route('dashboard.bookings.show', $newBooking)->with('status', 'booking-rescheduled');
    }

    /**
     * Retry adding a booking to the host's calendar after a failure.
     */
    public function retrySync(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeHost($request, $booking);

        abort_unless($booking->status === BookingStatus::Confirmed, 404);

        SyncBookingToCalendar::dispatch($booking);

        return back()->with('status', 'sync-queued');
    }

    private function authorizeHost(Request $request, Booking $booking): void
    {
        // 404 rather than 403 so other hosts' booking ids are not confirmed.
        abort_unless($booking->isAccessibleBy($request->user()), 404);
    }

    /**
     * Bookings the user organizes or co-hosts.
     *
     * @return Builder<Booking>
     */
    private function attended(User $user): Builder
    {
        return Booking::query()->attendedBy($user->id);
    }
}
