<?php

namespace Tests\Unit;

use App\Support\TimeOfDay;
use Tests\TestCase;

class TimeOfDayTest extends TestCase
{
    public function test_converts_times_to_minutes_and_back(): void
    {
        $this->assertSame(570, TimeOfDay::toMinutes('09:30'));
        $this->assertSame('09:30', TimeOfDay::format(570));
        $this->assertSame(0, TimeOfDay::toMinutes('00:00'));
    }

    public function test_midnight_as_an_end_means_end_of_day(): void
    {
        $this->assertSame(1440, TimeOfDay::toMinutes('00:00', isEnd: true));
        $this->assertSame('00:00', TimeOfDay::format(1440));
    }

    public function test_parses_and_sorts_intervals(): void
    {
        $this->assertSame(
            [[540, 720], [780, 1440]],
            TimeOfDay::parseIntervals([['start' => '13:00', 'end' => '00:00'], ['start' => '09:00', 'end' => '12:00']]),
        );
    }

    public function test_detects_inverted_and_overlapping_intervals(): void
    {
        $this->assertNull(TimeOfDay::validateIntervals([[540, 720], [720, 1020]]));
        $this->assertNotNull(TimeOfDay::validateIntervals([[720, 540]]));
        $this->assertNotNull(TimeOfDay::validateIntervals([[540, 720], [700, 1020]]));
    }
}
