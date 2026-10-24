<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotEngine;
use App\Services\Scheduling\SlotRules;
use App\Services\Scheduling\WeeklyAvailability;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The shared start-time grid for team event types. 2026-11-09 is a Monday.
 */
class AnchoredGridTest extends TestCase
{
    public function test_grid_is_anchored_to_the_hour_not_the_working_range(): void
    {
        $quarterPast = new WeeklyAvailability('UTC', [1 => [[555, 660]]]); // 09:15–11:00

        $this->assertSame(
            ['09:30', '10:00', '10:30'],
            $this->grid([$quarterPast], 30),
        );
        $this->assertSame(
            ['09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45'],
            $this->grid([$quarterPast], 15),
        );
    }

    public function test_hosts_share_one_grid_across_their_merged_hours(): void
    {
        $early = new WeeklyAvailability('UTC', [1 => [[540, 600]]]);     // 09:00–10:00
        $late = new WeeklyAvailability('UTC', [1 => [[585, 690]]]);      // 09:45–11:30
        $afternoon = new WeeklyAvailability('UTC', [1 => [[795, 840]]]); // 13:15–14:00

        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '13:30'],
            $this->grid([$late, $early, $afternoon], 30),
        );
    }

    public function test_grid_follows_each_hosts_own_hour_in_their_time_zone(): void
    {
        // Kolkata is UTC+5:30, so its 09:00 is 03:30 UTC; the grid keeps its local hours.
        $kolkata = new WeeklyAvailability('Asia/Kolkata', [1 => [[540, 720]]]);

        $this->assertSame(['03:30', '04:30', '05:30'], $this->grid([$kolkata], 60));
    }

    public function test_grid_respects_the_requested_range(): void
    {
        $day = new WeeklyAvailability('UTC', [1 => [[540, 1020]]]);

        $grid = (new SlotEngine)->anchoredGrid([$day], new Period('2026-11-09T10:10Z', '2026-11-09T11:00Z'), 30);

        $this->assertSame(['10:30'], array_map(fn (int $ts) => gmdate('H:i', $ts), $grid));
    }

    public function test_engine_checks_shared_candidates_against_a_hosts_own_hours(): void
    {
        $quarterPast = new WeeklyAvailability('UTC', [1 => [[555, 660]]]); // 09:15–11:00
        $candidates = array_map(fn (string $t) => CarbonImmutable::parse("2026-11-09T{$t}Z")->getTimestamp(), ['09:00', '09:30', '10:00', '10:30']);

        $slots = (new SlotEngine)->slots(
            new SlotRules(30),
            $quarterPast,
            new Period('2026-11-09T00:00Z', '2026-11-10T00:00Z'),
            CarbonImmutable::parse('2026-11-01T00:00Z'),
            busy: [new Period('2026-11-09T10:00Z', '2026-11-09T10:30Z')],
            candidates: $candidates,
        );

        // 09:00 is before their day starts; 10:00 is busy.
        $this->assertSame(['09:30', '10:30'], array_map(fn (CarbonImmutable $s) => $s->format('H:i'), $slots));
    }

    /**
     * @param  list<WeeklyAvailability>  $availabilities
     * @return list<string> UTC times on Monday 2026-11-09
     */
    private function grid(array $availabilities, int $step): array
    {
        $grid = (new SlotEngine)->anchoredGrid($availabilities, new Period('2026-11-09T00:00Z', '2026-11-10T00:00Z'), $step);

        return array_map(fn (int $ts) => gmdate('H:i', $ts), $grid);
    }
}
