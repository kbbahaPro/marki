<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\SchedulingType;
use App\Exceptions\SlotUnavailableException;
use App\Mail\BookingCancelledHost;
use App\Mail\BookingConfirmedGuest;
use App\Mail\BookingConfirmedHost;
use App\Mail\BookingRescheduledHost;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use App\Services\Ics\IcsGenerator;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/**
 * Hosts work Mon–Fri 09:00–17:00 UTC; "now" is 2026-11-01 10:00 UTC and
 * 2026-11-09 is a Monday.
 */
class TeamBookingTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private Team $team;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();
        $this->fakeGoogle();

        $this->team = Team::factory()->create();
        $this->alice = $this->member('Alice');
        $this->bob = $this->member('Bob');
        $this->carol = $this->member('Carol');
    }

    public function test_round_robin_assigns_one_rotating_host_plus_the_fixed_hosts(): void
    {
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => ['is_fixed' => true]]);

        $booking = $this->book($eventType, '2026-11-09T10:00Z');

        $this->assertTrue($booking->host->is($this->alice));
        $this->assertSame(['Alice', 'Bob'], $booking->hosts->pluck('name')->all());
        $this->assertTrue((bool) $booking->hosts->first()->pivot->is_organizer);
        $this->assertFalse((bool) $booking->hosts->last()->pivot->is_organizer);
        $this->assertSame('Alice and Bob', $booking->hostNames());

        Mail::assertQueued(BookingConfirmedGuest::class, fn ($mail) => $mail->hasTo('guest@example.com')
            && str_contains($mail->envelope()->subject, 'with Alice and Bob'));
        Mail::assertQueued(BookingConfirmedHost::class, 2);
        Mail::assertQueued(BookingConfirmedHost::class, fn ($mail) => $mail->hasTo($this->bob->email) && $mail->recipient->is($this->bob));
    }

    public function test_co_hosts_are_blocked_by_the_booking(): void
    {
        $collective = $this->collective([$this->alice, $this->bob]);
        $this->book($collective, '2026-11-09T10:00Z');

        $bobsOwn = EventType::factory()->for($this->bob)->create();

        $this->expectException(SlotUnavailableException::class);
        $this->book($bobsOwn, '2026-11-09T10:00Z');
    }

    public function test_round_robin_goes_to_the_host_with_fewer_recent_bookings(): void
    {
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => []]);

        $first = $this->book($eventType, '2026-11-09T10:00Z');
        $second = $this->book($eventType, '2026-11-09T11:00Z');
        $third = $this->book($eventType, '2026-11-09T12:00Z');

        // Alice (lower id) wins the tie, then Bob has fewer, then Alice was assigned less recently.
        $this->assertSame(['Alice', 'Bob', 'Alice'], [$first->host->name, $second->host->name, $third->host->name]);
    }

    public function test_bookings_older_than_30_days_and_cancelled_ones_do_not_count(): void
    {
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => []]);
        Booking::factory()->at($eventType, '2026-11-02T10:00Z')->create(['host_id' => $this->alice->id, 'created_at' => now()->subDays(31)]);
        Booking::factory()->at($eventType, '2026-11-03T10:00Z')->create(['host_id' => $this->alice->id, 'status' => BookingStatus::Cancelled]);
        Booking::factory()->at($eventType, '2026-11-04T10:00Z')->create(['host_id' => $this->bob->id, 'created_at' => now()->subDays(29)]);

        $this->assertTrue($this->book($eventType, '2026-11-09T10:00Z')->host->is($this->alice));
    }

    public function test_priority_comes_before_fairness_and_weight_scales_it(): void
    {
        $byPriority = $this->roundRobin([$this->alice->id => ['priority' => 1], $this->bob->id => ['priority' => 3]]);
        foreach (['10:00', '11:00', '12:00'] as $time) {
            Booking::factory()->at($byPriority, "2026-11-02T{$time}Z")->create(['host_id' => $this->bob->id]);
        }

        $this->assertTrue($this->book($byPriority, '2026-11-09T10:00Z')->host->is($this->bob));

        // Same bookings each; Alice's double weight makes her relatively less busy.
        $byWeight = $this->roundRobin([$this->alice->id => ['weight' => 200], $this->bob->id => ['weight' => 100]]);
        Booking::factory()->at($byWeight, '2026-11-03T10:00Z')->create(['host_id' => $this->alice->id]);
        Booking::factory()->at($byWeight, '2026-11-03T11:00Z')->create(['host_id' => $this->bob->id]);

        $this->assertTrue($this->book($byWeight, '2026-11-10T10:00Z')->host->is($this->alice));
    }

    public function test_round_robin_skips_busy_hosts_and_fails_when_none_is_free(): void
    {
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => []]);
        $this->personalBooking($this->alice, '2026-11-09T10:00Z');

        $this->assertTrue($this->book($eventType, '2026-11-09T10:00Z')->host->is($this->bob));

        $this->expectException(SlotUnavailableException::class);
        $this->book($eventType, '2026-11-09T10:00Z');
    }

    public function test_collective_bookings_include_every_host(): void
    {
        $eventType = $this->collective([$this->carol, $this->alice, $this->bob]);

        $booking = $this->book($eventType, '2026-11-09T10:00Z');

        // Nobody has Google connected, so the lowest id organizes.
        $this->assertTrue($booking->host->is($this->alice));
        $this->assertSame(['Alice', 'Bob', 'Carol'], $booking->hosts->pluck('name')->all());
        Mail::assertQueued(BookingConfirmedHost::class, 3);

        $ics = app(IcsGenerator::class)->forBooking($booking);
        $this->assertStringContainsString('ORGANIZER;CN="Alice":mailto:'.$this->alice->email, $ics);
        $this->assertStringContainsString('ATTENDEE;CN="Bob";', $ics);
        $this->assertStringContainsString('ATTENDEE;CN="Carol";', $ics);
    }

    public function test_collective_organizer_is_a_host_with_google_connected_and_co_hosts_are_attendees(): void
    {
        $this->connectGoogle($this->bob);
        $this->queueGoogle(
            $this->freeBusyResponse(),
            $this->googleJson(['id' => 'evt', 'status' => 'confirmed', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']),
        );
        $eventType = $this->collective([$this->alice, $this->bob]);

        $booking = $this->book($eventType, '2026-11-09T10:00Z');

        $this->assertTrue($booking->host->is($this->bob));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $booking->meeting_url);

        $insert = $this->requestJson($this->googleRequests()[1]);
        $this->assertEquals([
            ['email' => 'guest@example.com', 'displayName' => 'Grace'],
            ['email' => $this->alice->email, 'displayName' => 'Alice'],
        ], $insert['attendees']);
    }

    public function test_round_robin_reschedule_keeps_the_host_when_free(): void
    {
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => []]);
        $booking = $this->book($eventType, '2026-11-09T10:00Z');
        // Bob gets this one, so fairness alone would not prefer Alice below.
        $this->book($eventType, '2026-11-09T11:00Z');

        [$moved] = app(BookingService::class)->reschedule($booking, CarbonImmutable::parse('2026-11-09T14:00Z'), CarbonImmutable::now());

        $this->assertTrue($moved->host->is($this->alice));
        Mail::assertQueued(BookingRescheduledHost::class, fn ($mail) => $mail->hasTo($this->alice->email));
        Mail::assertNotQueued(BookingCancelledHost::class);
    }

    public function test_round_robin_reschedule_reassigns_when_the_host_is_busy(): void
    {
        $this->connectGoogle($this->alice);
        $this->queueGoogle(
            $this->freeBusyResponse(),
            $this->googleJson(['id' => 'alice-event', 'status' => 'confirmed', 'hangoutLink' => 'https://meet.google.com/aaa-bbbb-ccc']),
            $this->freeBusyResponse(),
            new Response(204),
        );
        $eventType = $this->roundRobin([$this->alice->id => [], $this->bob->id => []]);
        $booking = $this->book($eventType, '2026-11-09T10:00Z');
        $this->assertTrue($booking->host->is($this->alice));
        $this->personalBooking($this->alice, '2026-11-09T14:00Z');

        [$moved] = app(BookingService::class)->reschedule($booking, CarbonImmutable::parse('2026-11-09T14:00Z'), CarbonImmutable::now());

        $this->assertTrue($moved->host->is($this->bob));
        $this->assertSame(['Bob'], $moved->hosts->pluck('name')->all());
        // The event left Alice's calendar; Bob has no Google, so there is no new Meet link.
        $this->assertSame('DELETE', $this->googleRequests()[3]->getMethod());
        $this->assertStringEndsWith('/events/alice-event', $this->googleRequests()[3]->getUri()->getPath());
        $this->assertNull($moved->fresh()->meeting_url);

        Mail::assertQueued(BookingRescheduledHost::class, fn ($mail) => $mail->hasTo($this->bob->email));
        Mail::assertQueued(BookingCancelledHost::class, fn ($mail) => $mail->hasTo($this->alice->email) && $mail->reassigned
            && str_starts_with($mail->envelope()->subject, 'Moved to another host'));
    }

    public function test_collective_reschedule_needs_every_host_free(): void
    {
        $eventType = $this->collective([$this->alice, $this->bob]);
        $booking = $this->book($eventType, '2026-11-09T10:00Z');
        $this->personalBooking($this->bob, '2026-11-09T14:00Z');

        try {
            app(BookingService::class)->reschedule($booking, CarbonImmutable::parse('2026-11-09T14:00Z'), CarbonImmutable::now());
            $this->fail('The slot should be unavailable.');
        } catch (SlotUnavailableException) {
        }

        [$moved] = app(BookingService::class)->reschedule($booking, CarbonImmutable::parse('2026-11-09T15:00Z'), CarbonImmutable::now());
        $this->assertTrue($moved->host->is($booking->host));
        $this->assertSame(['Alice', 'Bob'], $moved->hosts->pluck('name')->all());
        // Moving onto its own old time is fine too.
        app(BookingService::class)->reschedule($moved, CarbonImmutable::parse('2026-11-09T15:30Z'), CarbonImmutable::now());
    }

    public function test_cancelling_tells_every_host(): void
    {
        $booking = $this->book($this->collective([$this->alice, $this->bob]), '2026-11-09T10:00Z');

        app(BookingService::class)->cancel($booking, 'guest');

        Mail::assertQueued(BookingCancelledHost::class, 2);
    }

    public function test_co_hosts_cannot_delete_their_account(): void
    {
        $this->book($this->collective([$this->alice, $this->bob]), '2026-11-09T10:00Z');

        $this->assertNotNull($this->bob->fresh()->deletionBlocker());
    }

    /**
     * @param  array<int, array<string, mixed>>  $hosts  user id => pivot attributes
     */
    private function roundRobin(array $hosts): EventType
    {
        $eventType = EventType::factory()->forTeam($this->team)->create(['duration_minutes' => 30]);
        $eventType->hosts()->attach($hosts);

        return $eventType;
    }

    /**
     * @param  list<User>  $hosts
     */
    private function collective(array $hosts): EventType
    {
        $eventType = EventType::factory()->forTeam($this->team, SchedulingType::Collective)->create(['duration_minutes' => 30]);
        $eventType->hosts()->attach(collect($hosts)->pluck('id'), ['is_fixed' => true]);

        return $eventType;
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'timezone' => 'UTC']);
        $this->team->members()->attach($user, ['role' => 'member']);

        return $user;
    }

    private function book(EventType $eventType, string $start): Booking
    {
        [$booking] = app(BookingService::class)->book(
            $eventType,
            CarbonImmutable::parse($start),
            new GuestDetails('Grace', 'guest@example.com', 'UTC'),
            CarbonImmutable::now(),
        );

        return $booking->fresh(['host', 'hosts']);
    }

    private function personalBooking(User $host, string $start): Booking
    {
        $eventType = EventType::factory()->for($host)->create(['duration_minutes' => 60]);

        return Booking::factory()->at($eventType, $start)->create();
    }
}
