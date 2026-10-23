<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotEngine;
use App\Services\Scheduling\SlotRules;
use App\Services\Scheduling\WeeklyAvailability;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11-09 is a Monday. Unless stated otherwise the host works
 * Mon–Fri 09:00–17:00 and "now" is well before the requested range.
 */
class SlotEngineTest extends TestCase
{
    private const NINE_TO_FIVE = [[540, 1020]];

    // --- Basics -----------------------------------------------------------

    public function test_offers_back_to_back_slots_inside_working_hours(): void
    {
        $slots = $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z');

        $this->assertCount(16, $slots);
        $this->assertSame('2026-11-09T09:00Z', $slots[0]);
        $this->assertSame('2026-11-09T16:30Z', end($slots));
    }

    public function test_custom_interval_between_start_times(): void
    {
        $availability = new WeeklyAvailability('UTC', [1 => [[540, 660]]]);

        $this->assertSame(
            ['2026-11-09T09:00Z', '2026-11-09T09:15Z', '2026-11-09T09:30Z', '2026-11-09T09:45Z', '2026-11-09T10:00Z'],
            $this->slots(new SlotRules(60, intervalMinutes: 15), $availability, '2026-11-09T00:00Z', '2026-11-10T00:00Z'),
        );
    }

    public function test_days_without_hours_have_no_slots(): void
    {
        // Saturday and Sunday.
        $this->assertSame([], $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-07T00:00Z', '2026-11-09T00:00Z'));
    }

    public function test_only_slots_inside_the_requested_range_are_returned(): void
    {
        $this->assertSame(
            ['2026-11-09T10:00Z', '2026-11-09T10:30Z', '2026-11-09T11:00Z', '2026-11-09T11:30Z'],
            $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-09T09:45Z', '2026-11-09T12:00Z'),
        );
    }

    public function test_adjacent_ranges_merge_so_slots_can_span_them(): void
    {
        $availability = new WeeklyAvailability('UTC', [1 => [[540, 720], [720, 780]]]);

        $this->assertContains(
            '2026-11-09T11:30Z',
            $this->slots(new SlotRules(60, intervalMinutes: 30), $availability, '2026-11-09T00:00Z', '2026-11-10T00:00Z'),
        );
    }

    public function test_ranges_meeting_at_midnight_merge_across_days(): void
    {
        // Saturday 22:00–24:00 and Sunday 00:00–02:00.
        $availability = new WeeklyAvailability('UTC', [6 => [[1320, 1440]], 0 => [[0, 120]]]);

        $this->assertSame(
            ['2026-11-07T22:00Z', '2026-11-07T22:30Z', '2026-11-07T23:00Z', '2026-11-07T23:30Z',
                '2026-11-08T00:00Z', '2026-11-08T00:30Z', '2026-11-08T01:00Z'],
            $this->slots(new SlotRules(60, intervalMinutes: 30), $availability, '2026-11-07T00:00Z', '2026-11-09T00:00Z'),
        );
    }

    // --- Date overrides ---------------------------------------------------

    public function test_a_day_off_override_removes_the_day(): void
    {
        $availability = new WeeklyAvailability('UTC', $this->weekdayRules(), ['2026-11-09' => []]);

        $this->assertSame([], $this->slots(new SlotRules(30), $availability, '2026-11-09T00:00Z', '2026-11-10T00:00Z'));
    }

    public function test_an_hours_override_replaces_the_weekly_hours(): void
    {
        $availability = new WeeklyAvailability('UTC', $this->weekdayRules(), ['2026-11-10' => [[600, 660]]]);

        $this->assertSame(
            ['2026-11-10T10:00Z', '2026-11-10T10:30Z'],
            $this->slots(new SlotRules(30), $availability, '2026-11-10T00:00Z', '2026-11-11T00:00Z'),
        );
    }

    public function test_overrides_apply_to_the_hosts_local_date_not_the_utc_date(): void
    {
        // 09:00–17:00 in Tokyo on Tuesday 10 Nov is 00:00–08:00 UTC on the 10th;
        // the override for the local 10th must remove exactly those.
        $availability = new WeeklyAvailability('Asia/Tokyo', $this->weekdayRules(), ['2026-11-10' => []]);

        $slots = $this->slots(new SlotRules(60), $availability, '2026-11-09T00:00Z', '2026-11-11T00:00Z');

        $this->assertSame(
            ['2026-11-09T00:00Z', '2026-11-09T01:00Z', '2026-11-09T02:00Z', '2026-11-09T03:00Z',
                '2026-11-09T04:00Z', '2026-11-09T05:00Z', '2026-11-09T06:00Z', '2026-11-09T07:00Z'],
            $slots,
        );
    }

    // --- Busy times and buffers -----------------------------------------

    public function test_busy_times_block_overlapping_slots(): void
    {
        $slots = $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', busy: [
            new Period('2026-11-09T10:00Z', '2026-11-09T11:00Z'),
        ]);

        $this->assertContains('2026-11-09T09:30Z', $slots);
        $this->assertNotContains('2026-11-09T10:00Z', $slots);
        $this->assertNotContains('2026-11-09T10:30Z', $slots);
        $this->assertContains('2026-11-09T11:00Z', $slots);
    }

    public function test_overlapping_and_partial_busy_events(): void
    {
        $slots = $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', busy: [
            new Period('2026-11-09T11:00Z', '2026-11-09T12:00Z'),
            new Period('2026-11-09T10:00Z', '2026-11-09T11:30Z'),
            // A ten-minute event still blocks the whole slot around it.
            new Period('2026-11-09T14:10Z', '2026-11-09T14:20Z'),
            // A long event that started the day before.
            new Period('2026-11-08T20:00Z', '2026-11-09T09:30Z'),
        ]);

        $this->assertSame(
            ['2026-11-09T09:30Z', '2026-11-09T12:00Z', '2026-11-09T12:30Z', '2026-11-09T13:00Z', '2026-11-09T13:30Z',
                '2026-11-09T14:30Z', '2026-11-09T15:00Z', '2026-11-09T15:30Z', '2026-11-09T16:00Z', '2026-11-09T16:30Z'],
            $slots,
        );
    }

    public function test_buffer_before_keeps_distance_from_a_preceding_event(): void
    {
        $slots = $this->slots(new SlotRules(30, bufferBeforeMinutes: 15), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', busy: [
            new Period('2026-11-09T10:00Z', '2026-11-09T11:00Z'),
        ]);

        $this->assertContains('2026-11-09T09:30Z', $slots);
        $this->assertNotContains('2026-11-09T11:00Z', $slots);
        $this->assertContains('2026-11-09T11:30Z', $slots);
    }

    public function test_buffer_after_keeps_distance_from_a_following_event(): void
    {
        $slots = $this->slots(new SlotRules(30, bufferAfterMinutes: 15), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', busy: [
            new Period('2026-11-09T10:00Z', '2026-11-09T11:00Z'),
        ]);

        $this->assertContains('2026-11-09T09:00Z', $slots);
        $this->assertNotContains('2026-11-09T09:30Z', $slots);
        $this->assertContains('2026-11-09T11:00Z', $slots);
    }

    public function test_buffers_may_extend_outside_working_hours(): void
    {
        $slots = $this->slots(new SlotRules(30, bufferBeforeMinutes: 30, bufferAfterMinutes: 30), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z');

        $this->assertSame('2026-11-09T09:00Z', $slots[0]);
        $this->assertSame('2026-11-09T16:30Z', end($slots));
    }

    // --- Notice and booking window --------------------------------------

    public function test_minimum_notice_hides_slots_that_start_too_soon(): void
    {
        $slots = $this->slots(new SlotRules(30, minNoticeMinutes: 60), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', now: '2026-11-09T10:10Z');

        // Earliest allowed start is 11:10; start times stay on the :00/:30 grid.
        $this->assertSame('2026-11-09T11:30Z', $slots[0]);
    }

    public function test_slots_in_the_past_are_never_offered(): void
    {
        $slots = $this->slots(new SlotRules(30), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-10T00:00Z', now: '2026-11-09T10:10Z');

        $this->assertSame('2026-11-09T10:30Z', $slots[0]);
    }

    public function test_max_days_ahead_counts_host_calendar_days(): void
    {
        $slots = $this->slots(new SlotRules(60, maxDaysAhead: 1), $this->weekdays('UTC'), '2026-11-09T00:00Z', '2026-11-13T00:00Z', now: '2026-11-09T12:00Z');

        $days = array_unique(array_map(fn ($s) => substr($s, 0, 10), $slots));
        $this->assertSame(['2026-11-09', '2026-11-10'], array_values($days));
        $this->assertSame('2026-11-10T16:00Z', end($slots));
    }

    // --- Daily limit ----------------------------------------------------

    public function test_daily_limit_is_counted_per_host_local_day(): void
    {
        // Los Angeles host working 16:00–24:00: their Monday 9 Nov is 00:00–08:00 UTC on the 10th.
        $availability = new WeeklyAvailability('America/Los_Angeles', [1 => [[960, 1440]], 2 => [[960, 1440]]]);

        $slots = $this->slots(new SlotRules(60, dailyLimit: 1), $availability, '2026-11-09T00:00Z', '2026-11-12T00:00Z', bookingsPerDay: [
            '2026-11-09' => 1,
        ]);

        $this->assertCount(8, $slots);
        $this->assertSame('2026-11-11T00:00Z', $slots[0]);
    }

    // --- DST ------------------------------------------------------------

    public function test_working_hours_follow_the_local_clock_across_spring_forward(): void
    {
        // US clocks go forward on Sunday 14 March 2027.
        $availability = new WeeklyAvailability('America/New_York', [6 => self::NINE_TO_FIVE, 0 => self::NINE_TO_FIVE]);

        $slots = $this->slots(new SlotRules(60), $availability, '2027-03-13T00:00Z', '2027-03-15T00:00Z', now: '2027-03-01T00:00Z');

        $this->assertCount(16, $slots);
        $this->assertSame('2027-03-13T14:00Z', $slots[0]);   // 09:00 EST
        $this->assertSame('2027-03-14T13:00Z', $slots[8]);   // 09:00 EDT
        $this->assertSame('2027-03-14T20:00Z', end($slots)); // 16:00 EDT
    }

    public function test_hours_spanning_the_spring_forward_gap_lose_the_missing_hour(): void
    {
        // 01:00–04:00 local on the change day is only two real hours.
        $availability = new WeeklyAvailability('America/New_York', [0 => [[60, 240]]]);

        $this->assertSame(
            ['2027-03-14T06:00Z', '2027-03-14T07:00Z'], // 01:00 EST, 03:00 EDT
            $this->slots(new SlotRules(60), $availability, '2027-03-14T00:00Z', '2027-03-15T00:00Z', now: '2027-03-01T00:00Z'),
        );
    }

    public function test_hours_spanning_the_fall_back_overlap_gain_the_repeated_hour(): void
    {
        // US clocks go back on Sunday 7 November 2027: 00:00–03:00 local is four real hours.
        $availability = new WeeklyAvailability('America/New_York', [0 => [[0, 180]]]);

        $this->assertSame(
            ['2027-11-07T04:00Z', '2027-11-07T05:00Z', '2027-11-07T06:00Z', '2027-11-07T07:00Z'],
            $this->slots(new SlotRules(60), $availability, '2027-11-07T00:00Z', '2027-11-08T00:00Z', now: '2027-11-01T00:00Z'),
        );
    }

    public function test_working_hours_follow_the_local_clock_across_european_dst(): void
    {
        // EU clocks go forward on Sunday 28 March 2027 and back on Sunday 31 October 2027.
        $availability = new WeeklyAvailability('Europe/Paris', array_fill(0, 7, self::NINE_TO_FIVE));
        $rules = new SlotRules(60);

        $this->assertSame('2027-03-27T08:00Z', $this->slots($rules, $availability, '2027-03-27T00:00Z', '2027-03-28T00:00Z', now: '2027-03-01T00:00Z')[0]);
        $this->assertSame('2027-03-28T07:00Z', $this->slots($rules, $availability, '2027-03-28T00:00Z', '2027-03-29T00:00Z', now: '2027-03-01T00:00Z')[0]);
        $this->assertSame('2027-10-31T08:00Z', $this->slots($rules, $availability, '2027-10-31T00:00Z', '2027-11-01T00:00Z', now: '2027-10-01T00:00Z')[0]);
    }

    public function test_slot_intervals_stay_consistent_on_a_dst_change_day(): void
    {
        // A 24-hour schedule on the spring-forward day yields 23 hourly slots.
        $availability = new WeeklyAvailability('America/New_York', [0 => [[0, 1440]]]);

        $slots = $this->slots(new SlotRules(60), $availability, '2027-03-14T05:00Z', '2027-03-15T04:00Z', now: '2027-03-01T00:00Z');

        $this->assertCount(23, $slots);
    }

    // --- Host and guest in different time zones -------------------------

    public function test_guest_sees_a_tokyo_hosts_monday_morning_as_their_sunday_afternoon(): void
    {
        $availability = new WeeklyAvailability('Asia/Tokyo', [1 => [[540, 720]]]); // Mon 09:00–12:00 JST

        // The guest in Los Angeles asks for their own Sunday 8 November (PST, UTC-8).
        $slots = (new SlotEngine)->slots(
            new SlotRules(30),
            $availability,
            new Period('2026-11-08T08:00Z', '2026-11-09T08:00Z'),
            CarbonImmutable::parse('2026-11-01T00:00Z'),
        );

        $grouped = SlotEngine::groupByDate($slots, 'America/Los_Angeles');

        $this->assertSame(['2026-11-08'], array_keys($grouped));
        $this->assertSame(
            ['16:00', '16:30', '17:00', '17:30', '18:00', '18:30'],
            array_map(fn ($s) => $s->format('H:i'), $grouped['2026-11-08']),
        );
        $this->assertSame('2026-11-09T00:00:00+00:00', $slots[0]->toIso8601String());
    }

    public function test_guest_and_host_on_different_dst_schedules(): void
    {
        // Between the EU change (31 Oct) and the US change (7 Nov 2027) London is
        // UTC+0 while New York is still UTC-4, so the usual 5-hour gap is 4 hours.
        $availability = new WeeklyAvailability('Europe/London', [1 => self::NINE_TO_FIVE]);

        $slots = $this->slots(new SlotRules(60), $availability, '2027-11-01T00:00Z', '2027-11-02T00:00Z', now: '2027-10-01T00:00Z');
        $grouped = SlotEngine::groupByDate(array_map(fn ($s) => CarbonImmutable::parse($s), $slots), 'America/New_York');

        $this->assertSame('05:00', $grouped['2027-11-01'][0]->format('H:i'));
    }

    // --- Helpers --------------------------------------------------------

    /**
     * @param  list<Period>  $busy
     * @param  array<string, int>  $bookingsPerDay
     * @return list<string> Slot starts formatted as compact UTC ISO strings.
     */
    private function slots(
        SlotRules $rules,
        WeeklyAvailability $availability,
        string $from,
        string $until,
        array $busy = [],
        string $now = '2026-11-01T00:00Z',
        array $bookingsPerDay = [],
    ): array {
        $slots = (new SlotEngine)->slots(
            $rules,
            $availability,
            new Period($from, $until),
            CarbonImmutable::parse($now),
            $busy,
            $bookingsPerDay,
        );

        return array_map(fn (CarbonImmutable $s) => $s->utc()->format('Y-m-d\TH:i\Z'), $slots);
    }

    private function weekdays(string $timezone): WeeklyAvailability
    {
        return new WeeklyAvailability($timezone, $this->weekdayRules());
    }

    /**
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private function weekdayRules(): array
    {
        return array_fill_keys([1, 2, 3, 4, 5], self::NINE_TO_FIVE);
    }
}
