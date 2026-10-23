<?php

namespace Tests\Feature\Scheduling;

use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotRules;
use App\Services\Scheduling\WeeklyAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stored schedules and event types map onto the slot engine's inputs.
 */
class EngineInputsTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_availability_is_built_from_a_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Paris']);
        $schedule = $user->defaultSchedule;
        $schedule->rules()->create(['weekday' => 1, 'start_minute' => 1080, 'end_minute' => 1200]);
        $schedule->overrides()->createMany([
            ['date' => '2026-11-10'],
            ['date' => '2026-11-11', 'start_minute' => 840, 'end_minute' => 900],
            ['date' => '2026-11-11', 'start_minute' => 600, 'end_minute' => 660],
            // Both a day-off row and hours on one date: the day off wins.
            ['date' => '2026-11-12', 'start_minute' => 600, 'end_minute' => 660],
            ['date' => '2026-11-12'],
            ['date' => '2027-01-01'],
        ]);

        $availability = WeeklyAvailability::fromSchedule($schedule, new Period('2026-11-09T00:00Z', '2026-11-16T00:00Z'));

        $this->assertSame('Europe/Paris', $availability->timezone);
        $this->assertSame([[540, 1020], [1080, 1200]], $availability->rangesOn('2026-11-09'));
        $this->assertSame([], $availability->rangesOn('2026-11-10'));
        $this->assertSame([[600, 660], [840, 900]], $availability->rangesOn('2026-11-11'));
        $this->assertSame([], $availability->rangesOn('2026-11-12'));
        $this->assertSame([], $availability->rangesOn('2026-11-14')); // Saturday
        $this->assertArrayNotHasKey('2027-01-01', $availability->overrides);
    }

    public function test_slot_rules_are_built_from_an_event_type(): void
    {
        $eventType = EventType::factory()->create([
            'duration_minutes' => 45,
            'slot_interval_minutes' => null,
            'buffer_before_minutes' => 5,
            'buffer_after_minutes' => 10,
            'min_notice_minutes' => 120,
            'max_days_ahead' => 14,
            'daily_limit' => 3,
        ]);

        $rules = SlotRules::fromEventType($eventType);

        $this->assertSame(45, $rules->step());
        $this->assertSame(5, $rules->bufferBeforeMinutes);
        $this->assertSame(10, $rules->bufferAfterMinutes);
        $this->assertSame(120, $rules->minNoticeMinutes);
        $this->assertSame(14, $rules->maxDaysAhead);
        $this->assertSame(3, $rules->dailyLimit);
    }
}
