<?php

namespace Tests\Feature\Scheduling;

use App\Enums\SchedulingType;
use App\Exceptions\CalendarUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BusyTimeSource;
use App\Services\Scheduling\Period;
use App\Services\Scheduling\SlotEngine;
use App\Services\Scheduling\TeamSlots;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hosts work Mon–Fri 09:00–17:00 in their own time zone (the default
 * schedule). 2026-11-09 is a Monday; "now" is a week earlier.
 */
class TeamAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-02T00:00Z';

    private Team $team;

    private User $alice;

    private User $bob;

    /** @var array<int, list<Period>|Closure> Calendar busy times by host id, or a closure that throws. */
    private array $calendar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->alice = $this->member('Alice');
        $this->bob = $this->member('Bob');
    }

    public function test_collective_offers_only_times_when_every_host_is_free(): void
    {
        $eventType = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob]);
        $this->calendar[$this->alice->id] = [new Period('2026-11-09T10:00Z', '2026-11-09T11:00Z')];
        $this->personalBooking($this->bob, '2026-11-09T13:00Z');

        $starts = $this->starts($eventType);

        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('10:30', $starts);
        $this->assertNotContains('13:00', $starts);
        $this->assertContains('11:00', $starts);
        $this->assertNotContains('13:30', $starts);
        $this->assertCount(16 - 4, $starts);
        $this->assertSame([], $this->teamSlots($eventType)->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T11:00Z')));
    }

    public function test_collective_hosts_in_different_time_zones_share_their_overlap(): void
    {
        $this->alice->defaultSchedule->update(['timezone' => 'Europe/Paris']);       // 08:00–16:00 UTC
        $this->bob->defaultSchedule->update(['timezone' => 'America/New_York']);     // 14:00–22:00 UTC
        $eventType = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob]);

        $this->assertSame(['14:00', '14:30', '15:00', '15:30'], $this->starts($eventType));
    }

    public function test_hosts_with_different_start_times_still_share_slots(): void
    {
        // Bob starts at 09:15; his own grid would be 09:15, 09:45… and never meet Alice's.
        $this->bob->defaultSchedule->overrides()->create(['date' => '2026-11-09', 'start_minute' => 555, 'end_minute' => 1020]);
        $eventType = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob]);

        $starts = $this->starts($eventType);

        $this->assertSame('09:30', $starts[0]);
        $this->assertSame('16:30', end($starts));
        $this->assertCount(15, $starts);
    }

    public function test_round_robin_offers_a_time_when_any_rotating_host_is_free(): void
    {
        $eventType = $this->eventType(SchedulingType::RoundRobin, [$this->alice, $this->bob]);
        $this->calendar[$this->alice->id] = [new Period('2026-11-09T10:00Z', '2026-11-09T11:00Z')];
        $this->calendar[$this->bob->id] = [new Period('2026-11-09T10:30Z', '2026-11-09T12:00Z')];

        $slots = $this->teamSlots($eventType);

        $this->assertSame([$this->alice->id, $this->bob->id], $slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T09:30Z')));
        $this->assertSame([$this->bob->id], $slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T10:00Z')));
        $this->assertNull($slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T10:30Z')));
        $this->assertSame([$this->alice->id], $slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T11:00Z')));
    }

    public function test_round_robin_fixed_hosts_must_always_be_free(): void
    {
        $carol = $this->member('Carol');
        $eventType = $this->eventType(SchedulingType::RoundRobin, [$this->alice, $this->bob]);
        $eventType->hosts()->attach($carol, ['is_fixed' => true]);
        $this->calendar[$carol->id] = [new Period('2026-11-09T15:00Z', '2026-11-09T17:00Z')];

        $starts = $this->starts($eventType);

        $this->assertSame('14:30', end($starts));
        $this->assertCount(12, $starts);
    }

    public function test_round_robin_without_rotating_hosts_offers_nothing(): void
    {
        $eventType = $this->eventType(SchedulingType::RoundRobin, []);
        $eventType->hosts()->attach($this->alice, ['is_fixed' => true]);

        $this->assertSame([], $this->starts($eventType));
        $this->assertSame([], $this->starts($this->eventType(SchedulingType::Collective, [])));
    }

    public function test_the_daily_limit_applies_to_each_host(): void
    {
        $eventType = $this->eventType(SchedulingType::RoundRobin, [$this->alice, $this->bob], ['daily_limit' => 1]);
        Booking::factory()->at($eventType, '2026-11-09T09:00Z')->create(['host_id' => $this->alice->id]);

        $slots = $this->teamSlots($eventType, '2026-11-09T00:00Z', '2026-11-11T00:00Z');

        // Monday: Alice reached her limit, Bob has not. Tuesday: both are free.
        $this->assertSame([$this->bob->id], $slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T14:00Z')));
        $this->assertSame([$this->alice->id, $this->bob->id], $slots->rotatingHostsAt(CarbonImmutable::parse('2026-11-10T14:00Z')));
    }

    public function test_a_co_hosts_bookings_block_their_time(): void
    {
        $collective = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob], ['buffer_after_minutes' => 30]);
        $booking = Booking::factory()->at($collective, '2026-11-09T10:00Z')->create(['host_id' => $this->alice->id]);
        $booking->hosts()->attach($this->bob);

        $roundRobin = $this->eventType(SchedulingType::RoundRobin, [$this->bob]);

        $starts = $this->starts($roundRobin);
        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('10:30', $starts); // The booking's own buffer still guards it.
        $this->assertContains('11:00', $starts);
        $this->assertContains('09:30', $starts);
    }

    public function test_a_host_whose_calendar_cannot_be_read_counts_as_busy(): void
    {
        $this->calendar[$this->bob->id] = fn () => throw new CalendarUnavailableException('token revoked');

        $roundRobin = $this->eventType(SchedulingType::RoundRobin, [$this->alice, $this->bob]);
        $this->assertSame([$this->alice->id], $this->teamSlots($roundRobin)->rotatingHostsAt(CarbonImmutable::parse('2026-11-09T09:00Z')));

        $collective = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob]);
        $this->assertSame([], $this->starts($collective));
    }

    public function test_inactive_team_event_types_offer_nothing(): void
    {
        $eventType = $this->eventType(SchedulingType::RoundRobin, [$this->alice, $this->bob], ['is_active' => false]);

        $this->assertSame([], $this->starts($eventType));
    }

    public function test_slots_and_is_available_handle_team_event_types(): void
    {
        $eventType = $this->eventType(SchedulingType::Collective, [$this->alice, $this->bob]);
        $this->personalBooking($this->bob, '2026-11-09T13:00Z');
        $service = $this->service();
        $now = CarbonImmutable::parse(self::NOW);

        $this->assertCount(14, $service->slots($eventType, new Period('2026-11-09T00:00Z', '2026-11-10T00:00Z'), $now));
        $this->assertTrue($service->isAvailable($eventType, CarbonImmutable::parse('2026-11-09T12:30Z'), $now));
        $this->assertFalse($service->isAvailable($eventType, CarbonImmutable::parse('2026-11-09T13:00Z'), $now));
        $this->assertFalse($service->isAvailable($eventType, CarbonImmutable::parse('2026-11-09T12:15Z'), $now));
    }

    public function test_every_booking_lists_its_organizer_as_a_host(): void
    {
        $booking = $this->personalBooking($this->alice, '2026-11-09T13:00Z');

        $host = $booking->hosts->sole();
        $this->assertTrue($host->is($this->alice));
        $this->assertTrue((bool) $host->pivot->is_organizer);
        $this->assertSame([$booking->id], Booking::attendedBy($this->alice->id)->pluck('id')->all());
        $this->assertSame([], Booking::attendedBy($this->bob->id)->pluck('id')->all());
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'timezone' => 'UTC']);
        $this->team->members()->attach($user, ['role' => 'member']);

        return $user->load('defaultSchedule');
    }

    /**
     * @param  list<User>  $rotating  Hosts attached as non-fixed.
     * @param  array<string, mixed>  $attributes
     */
    private function eventType(SchedulingType $type, array $rotating, array $attributes = []): EventType
    {
        $eventType = EventType::factory()->forTeam($this->team, $type)->create($attributes + ['duration_minutes' => 30]);
        $eventType->hosts()->attach(collect($rotating)->pluck('id'));

        return $eventType;
    }

    private function personalBooking(User $host, string $start): Booking
    {
        $eventType = EventType::factory()->for($host)->create(['duration_minutes' => 60]);

        return Booking::factory()->at($eventType, $start)->create();
    }

    private function teamSlots(EventType $eventType, string $from = '2026-11-09T00:00Z', string $until = '2026-11-10T00:00Z'): TeamSlots
    {
        return $this->service()->teamSlots($eventType, new Period($from, $until), CarbonImmutable::parse(self::NOW));
    }

    /**
     * @return list<string> UTC start times on Monday 2026-11-09
     */
    private function starts(EventType $eventType): array
    {
        return array_map(fn (CarbonImmutable $start) => $start->format('H:i'), $this->teamSlots($eventType)->starts());
    }

    private function service(): AvailabilityService
    {
        $source = new class($this->calendar) implements BusyTimeSource
        {
            public function __construct(private array $calendar) {}

            public function busyPeriods(User $host, Period $range): array
            {
                $busy = $this->calendar[$host->id] ?? [];

                return $busy instanceof Closure ? $busy() : $busy;
            }
        };

        return new AvailabilityService(new SlotEngine, [$source]);
    }
}
