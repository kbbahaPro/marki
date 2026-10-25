<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\SyncStatus;
use App\Events\BookingOutcomeRecorded;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Completed and no-show bookings. "Now" is 2026-11-09 09:15 UTC, during a
 * 09:00–09:30 meeting.
 */
class BookingOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-09 09:15:00');
        $this->host = User::factory()->create(['timezone' => 'UTC']);
        $this->eventType = EventType::factory()->for($this->host)->create(['duration_minutes' => 30]);
    }

    public function test_a_host_can_mark_a_started_meeting_as_a_no_show_and_correct_it(): void
    {
        Event::fake([BookingOutcomeRecorded::class]);
        $booking = $this->booking('2026-11-09 09:00');

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")
            ->assertSee('Mark as no-show')->assertSee('Mark as completed');

        $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'no_show'])
            ->assertRedirect("/dashboard/bookings/{$booking->uid}");
        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->status);

        $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")
            ->assertSee('No-show')->assertSee('Mark as completed')->assertDontSee('Mark as no-show');

        $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'completed']);
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);

        Event::assertDispatchedTimes(BookingOutcomeRecorded::class, 2);
    }

    public function test_marking_the_same_outcome_twice_records_it_once(): void
    {
        Event::fake([BookingOutcomeRecorded::class]);
        $booking = $this->booking('2026-11-09 09:00', ['status' => BookingStatus::NoShow]);

        $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'no_show']);

        Event::assertNotDispatched(BookingOutcomeRecorded::class);
    }

    public function test_future_cancelled_and_moved_bookings_cannot_be_marked(): void
    {
        Event::fake([BookingOutcomeRecorded::class]);
        $future = $this->booking('2026-11-10 09:00');
        $cancelled = $this->booking('2026-11-09 08:00', ['status' => BookingStatus::Cancelled, 'cancelled_by' => 'guest']);
        $moved = $this->booking('2026-11-09 07:00', ['status' => BookingStatus::Rescheduled]);

        foreach ([$future, $cancelled, $moved] as $booking) {
            $this->actingAs($this->host)->get("/dashboard/bookings/{$booking->uid}")->assertDontSee('Mark as no-show');
            $this->actingAs($this->host)->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'no_show'])
                ->assertSessionHas('error');
            $this->assertNotSame(BookingStatus::NoShow, $booking->fresh()->status);
        }

        Event::assertNotDispatched(BookingOutcomeRecorded::class);
    }

    public function test_only_the_bookings_hosts_can_mark_it(): void
    {
        $booking = $this->booking('2026-11-09 09:00');

        $this->actingAs(User::factory()->create())
            ->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'no_show'])
            ->assertNotFound();
    }

    public function test_the_outcome_is_validated(): void
    {
        $booking = $this->booking('2026-11-09 09:00');

        $this->actingAs($this->host)
            ->post("/dashboard/bookings/{$booking->uid}/outcome", ['outcome' => 'cancelled'])
            ->assertSessionHasErrors('outcome');
    }

    public function test_bookings_complete_an_hour_after_they_end_unless_already_marked(): void
    {
        Event::fake([BookingOutcomeRecorded::class]);
        $this->travelTo('2026-11-09 12:00:00');
        $endedLongAgo = $this->booking('2026-11-09 10:00');   // ended 10:30
        $endedRecently = $this->booking('2026-11-09 11:00');  // ended 11:30, inside the hour
        $noShow = $this->booking('2026-11-09 09:00', ['status' => BookingStatus::NoShow]);
        $cancelled = $this->booking('2026-11-09 08:00', ['status' => BookingStatus::Cancelled, 'cancelled_by' => 'host']);

        $this->artisan('bookings:complete')->expectsOutputToContain('Marked 1 booking(s) as completed.')->assertSuccessful();

        $this->assertSame(BookingStatus::Completed, $endedLongAgo->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $endedRecently->fresh()->status);
        $this->assertSame(BookingStatus::NoShow, $noShow->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $cancelled->fresh()->status);
        Event::assertDispatchedTimes(BookingOutcomeRecorded::class, 1);
    }

    public function test_completed_and_no_show_bookings_are_listed_as_past(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $this->booking('2026-11-09 09:00', ['guest_name' => 'Done Dana', 'status' => BookingStatus::Completed]);
        $this->booking('2026-11-09 10:00', ['guest_name' => 'Absent Abe', 'status' => BookingStatus::NoShow]);

        $this->actingAs($this->host)->get('/dashboard?tab=past')
            ->assertSee('Done Dana')->assertSee('Absent Abe')->assertSee('No-show');
        $this->actingAs($this->host)->get('/dashboard?tab=cancelled')
            ->assertDontSee('Done Dana')->assertDontSee('Absent Abe');
    }

    public function test_the_upgrade_completes_past_bookings_without_events(): void
    {
        Event::fake([BookingOutcomeRecorded::class]);
        $past = $this->booking('2026-11-08 09:00');
        $upcoming = $this->booking('2026-11-10 09:00');
        $cancelled = $this->booking('2026-11-08 10:00', ['status' => BookingStatus::Cancelled, 'cancelled_by' => 'guest']);

        (require database_path('migrations/2026_11_02_000001_complete_past_bookings.php'))->up();

        $this->assertSame(BookingStatus::Completed, $past->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $upcoming->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $cancelled->fresh()->status);
        Event::assertNotDispatched(BookingOutcomeRecorded::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(string $startUtc, array $attributes = []): Booking
    {
        return Booking::factory()->at($this->eventType, $startUtc)->create($attributes + ['sync_status' => SyncStatus::Skipped]);
    }
}
