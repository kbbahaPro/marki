<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\RendersBookingCalendar;
use App\Http\Requests\StoreBookingRequest;
use App\Models\EventType;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Scheduling\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingPageController extends Controller
{
    use RendersBookingCalendar;

    /**
     * A host's public page listing their active event types.
     */
    public function profile(string $username): View
    {
        $host = User::where('username', $username)->firstOrFail();

        return view('public.profile', [
            'host' => $host,
            'eventTypes' => $host->eventTypes()->where('is_active', true)->orderBy('title')->get(),
        ]);
    }

    /**
     * The booking page: calendar, times and the booking form.
     */
    public function show(string $username, string $slug): View
    {
        $eventType = $this->eventType($username, $slug);

        return view('public.book', [
            'eventType' => $eventType,
            'host' => $eventType->user,
            'config' => $this->calendarConfig($eventType, route('public.slots', [$username, $slug])),
            'formAction' => route('public.book.store', [$username, $slug]),
            'reschedule' => null,
        ]);
    }

    public function slots(Request $request, AvailabilityService $availability, string $username, string $slug): JsonResponse
    {
        return $this->slotsResponse($request, $availability, $this->eventType($username, $slug));
    }

    /**
     * Book a time.
     */
    public function store(StoreBookingRequest $request, BookingService $bookings): RedirectResponse
    {
        try {
            [$booking, $token] = $bookings->book($request->eventType(), $request->start(), $request->guest());
        } catch (SlotUnavailableException) {
            return back()
                ->withInput()
                ->withErrors(['start' => __('Sorry, that time is no longer available. Please pick another one.')]);
        }

        // Shown once on the confirmation page; the same links are in the email.
        return redirect()->route('public.bookings.show', $booking)->with('manage_token', $token);
    }

    private function eventType(string $username, string $slug): EventType
    {
        return EventType::query()
            ->whereHas('user', fn ($q) => $q->where('username', $username))
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['user', 'questions'])
            ->firstOrFail();
    }
}
