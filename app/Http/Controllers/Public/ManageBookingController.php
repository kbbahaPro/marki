<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\BookingNotChangeableException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\RendersBookingCalendar;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Guest self-service through the private link in their emails. The link
 * holds a random 64-character token; only its SHA-256 hash is stored.
 */
class ManageBookingController extends Controller
{
    use RendersBookingCalendar;

    public function show(string $token): View
    {
        $booking = $this->booking($token);

        return view('public.manage', [
            'booking' => $booking,
            'token' => $token,
        ]);
    }

    public function cancel(Request $request, BookingService $bookings, string $token): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $booking = $this->booking($token);

        try {
            $bookings->cancel($booking, 'guest', $validated['reason'] ?? null);
        } catch (BookingNotChangeableException) {
            return redirect()->route('bookings.manage', $token)
                ->with('error', __('This booking can no longer be cancelled.'));
        }

        return redirect()->route('bookings.manage', $token)->with('status', 'cancelled');
    }

    public function editTime(string $token): View|RedirectResponse
    {
        $booking = $this->booking($token);

        if (! $booking->isChangeable() || ! $booking->eventType->is_active || $booking->eventType->trashed()) {
            return redirect()->route('bookings.manage', $token);
        }

        $eventType = $booking->eventType->load(['user', 'questions']);

        return view('public.book', [
            'eventType' => $eventType,
            'host' => $eventType->user,
            'config' => $this->calendarConfig($eventType, route('bookings.reschedule.slots', $token), $booking->guest_timezone),
            'formAction' => route('bookings.reschedule.store', $token),
            'reschedule' => $booking,
        ]);
    }

    public function slots(Request $request, AvailabilityService $availability, string $token): JsonResponse
    {
        $booking = $this->booking($token);

        abort_unless($booking->isChangeable(), 404);

        return $this->slotsResponse($request, $availability, $booking->eventType, ignoring: $booking);
    }

    public function reschedule(Request $request, BookingService $bookings, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'string', 'date'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $booking = $this->booking($token);

        try {
            [$newBooking, $newToken] = $bookings->reschedule($booking, CarbonImmutable::parse($validated['start']));
        } catch (BookingNotChangeableException) {
            return redirect()->route('bookings.manage', $token)
                ->with('error', __('This booking can no longer be rescheduled.'));
        } catch (SlotUnavailableException) {
            return back()->withInput()
                ->withErrors(['start' => __('Sorry, that time is no longer available. Please pick another one.')]);
        }

        // The old link stops working; the new one is shown once and emailed.
        return redirect()->route('public.bookings.show', $newBooking)
            ->with('manage_token', $newToken)
            ->with('rescheduled', true);
    }

    private function booking(string $token): Booking
    {
        $booking = Booking::findByManageToken($token);

        abort_if($booking === null, 404);

        return $booking->load(['eventType', 'host']);
    }
}
