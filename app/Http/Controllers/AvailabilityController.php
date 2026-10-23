<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleOverrideRequest;
use App\Http\Requests\UpdateAvailabilityRequest;
use App\Models\Schedule;
use App\Support\TimeOfDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AvailabilityController extends Controller
{
    /**
     * Display weekly hours and upcoming date overrides.
     */
    public function edit(Request $request): View
    {
        $schedule = $this->schedule($request);
        $today = now($schedule->timezone)->toDateString();

        $rulesByDay = $schedule->rules->groupBy('weekday');

        return view('availability.edit', [
            'schedule' => $schedule,
            // Monday-first, as most calendars display it.
            'days' => collect([1, 2, 3, 4, 5, 6, 0])->map(fn (int $weekday) => [
                'weekday' => $weekday,
                'intervals' => ($rulesByDay[$weekday] ?? collect())->map(fn ($rule) => [
                    'start' => TimeOfDay::format($rule->start_minute),
                    'end' => TimeOfDay::format($rule->end_minute),
                ])->values()->all(),
            ])->all(),
            'overrides' => $schedule->overrides()
                ->where('date', '>=', $today)
                ->get()
                ->groupBy(fn ($override) => $override->date->toDateString()),
            'today' => $today,
        ]);
    }

    /**
     * Replace the weekly hours and the schedule's time zone.
     */
    public function update(UpdateAvailabilityRequest $request): RedirectResponse
    {
        $schedule = $this->schedule($request);

        DB::transaction(function () use ($request, $schedule) {
            $schedule->update(['timezone' => $request->validated('timezone')]);
            $schedule->rules()->delete();
            $schedule->rules()->createMany($request->weeklyRules());
        });

        return redirect()->route('availability.edit')->with('status', 'availability-updated');
    }

    /**
     * Set the hours for one date, replacing any existing override for it.
     */
    public function storeOverride(StoreScheduleOverrideRequest $request): RedirectResponse
    {
        $schedule = $this->schedule($request);

        DB::transaction(function () use ($request, $schedule) {
            $schedule->overrides()->whereDate('date', $request->validated('date'))->delete();
            $schedule->overrides()->createMany($request->overrideRows());
        });

        return redirect()->route('availability.edit')->with('status', 'override-saved');
    }

    /**
     * Remove the override for a date so the weekly hours apply again.
     */
    public function destroyOverride(Request $request, string $date): RedirectResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);

        $this->schedule($request)->overrides()->whereDate('date', $date)->delete();

        return redirect()->route('availability.edit')->with('status', 'override-deleted');
    }

    private function schedule(Request $request): Schedule
    {
        return $request->user()->defaultSchedule()->with('rules')->firstOrFail();
    }
}
