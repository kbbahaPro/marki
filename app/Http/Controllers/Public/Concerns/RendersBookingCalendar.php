<?php

namespace App\Http\Controllers\Public\Concerns;

use App\Models\Booking;
use App\Models\EventType;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotEngine;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by the booking page and the guest's reschedule page.
 */
trait RendersBookingCalendar
{
    /**
     * Settings for the Alpine calendar component.
     *
     * @return array<string, mixed>
     */
    protected function calendarConfig(EventType $eventType, string $slotsUrl, ?string $defaultTimezone = null): array
    {
        // Team event types have no single host: start from the first host's zone.
        $hostTimezone = $eventType->effectiveSchedule()?->timezone
            ?? $eventType->user?->timezone
            ?? $eventType->hosts->first()?->timezone
            ?? config('app.timezone');

        return [
            'slotsUrl' => $slotsUrl,
            'defaultTimezone' => $defaultTimezone ?? $hostTimezone,
            'timezones' => DateTimeZone::listIdentifiers(),
            // Last host-local date that can be booked; the calendar stops there.
            'lastDate' => now($hostTimezone)->addDays($eventType->max_days_ahead)->toDateString(),
            'old' => [
                // Reopen the form on the picked time after a validation error,
                // unless the time itself was the problem.
                'start' => session('errors')?->has('start') ? null : old('start'),
                'timezone' => old('timezone'),
            ],
        ];
    }

    /**
     * Available start times for one month, grouped by date in the guest's time zone.
     */
    protected function slotsResponse(Request $request, AvailabilityService $availability, EventType $eventType, ?Booking $ignoring = null): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $validated['month'], $validated['timezone']);

        $slots = $availability->slots($eventType, new Period($monthStart, $monthStart->addMonth()), ignoring: $ignoring);

        $days = collect(SlotEngine::groupByDate($slots, $validated['timezone']))
            ->map(fn (array $starts) => array_map(fn (CarbonImmutable $s) => $s->toIso8601String(), $starts));

        return response()->json([
            'timezone' => $validated['timezone'],
            'days' => (object) $days->all(),
        ])->header('Cache-Control', 'no-store');
    }
}
