<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Models\EventType;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotEngine;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingPageController extends Controller
{
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
        $hostTimezone = $eventType->effectiveSchedule()?->timezone ?? $eventType->user->timezone;

        return view('public.book', [
            'eventType' => $eventType,
            'host' => $eventType->user,
            'config' => [
                'slotsUrl' => route('public.slots', [$username, $slug]),
                'defaultTimezone' => $hostTimezone,
                'timezones' => DateTimeZone::listIdentifiers(),
                // Last host-local date that can be booked; the calendar stops there.
                'lastDate' => now($hostTimezone)->addDays($eventType->max_days_ahead)->toDateString(),
                'old' => [
                    // Reopen the form on the picked time after a validation error,
                    // unless the time itself was the problem.
                    'start' => session('errors')?->has('start') ? null : old('start'),
                    'timezone' => old('timezone'),
                ],
            ],
        ]);
    }

    /**
     * Available start times for one month, grouped by date in the guest's time zone.
     */
    public function slots(Request $request, AvailabilityService $availability, string $username, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $eventType = $this->eventType($username, $slug);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $validated['month'], $validated['timezone']);

        $slots = $availability->slots($eventType, new Period($monthStart, $monthStart->addMonth()));

        $days = collect(SlotEngine::groupByDate($slots, $validated['timezone']))
            ->map(fn (array $starts) => array_map(fn (CarbonImmutable $s) => $s->toIso8601String(), $starts));

        return response()->json([
            'timezone' => $validated['timezone'],
            'days' => (object) $days->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Book a time.
     */
    public function store(StoreBookingRequest $request, BookingService $bookings): RedirectResponse
    {
        try {
            [$booking] = $bookings->book($request->eventType(), $request->start(), $request->guest());
        } catch (SlotUnavailableException) {
            return back()
                ->withInput()
                ->withErrors(['start' => __('Sorry, that time is no longer available. Please pick another one.')]);
        }

        return redirect()->route('public.bookings.show', $booking);
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
