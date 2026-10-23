<?php

namespace Tests\Feature\Public;

use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Events\BookingCreated;
use App\Exceptions\CalendarUnavailableException;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Scheduling\BusyTimeSource;
use App\Services\Scheduling\Period;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The host works Mon–Fri 09:00–17:00 UTC (the default schedule). "Now" is
 * Sunday 2026-11-01 10:00 UTC; Monday 2026-11-09 is the usual booking day.
 */
class BookingPageTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        $this->host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host']);
        $this->eventType = EventType::factory()->for($this->host)->create([
            'title' => 'Intro call',
            'slug' => 'intro',
            'duration_minutes' => 30,
        ]);
    }

    // --- Pages ----------------------------------------------------------

    public function test_profile_lists_only_active_event_types(): void
    {
        EventType::factory()->for($this->host)->create(['title' => 'Secret call', 'is_active' => false]);

        $this->get('/jane')->assertOk()->assertSee('Intro call')->assertDontSee('Secret call');
        $this->get('/nobody-here')->assertNotFound();
    }

    public function test_booking_page_is_shown_for_active_event_types_only(): void
    {
        $this->get('/jane/intro')->assertOk()->assertSee('Intro call')->assertSee('Jane Host');

        $this->get('/jane/nope')->assertNotFound();
        $this->get('/someone-else/intro')->assertNotFound();

        $this->eventType->update(['is_active' => false]);
        $this->get('/jane/intro')->assertNotFound();

        $this->eventType->update(['is_active' => true]);
        $this->eventType->delete();
        $this->get('/jane/intro')->assertNotFound();
    }

    public function test_user_content_is_escaped_on_the_booking_page(): void
    {
        $this->eventType->update(['description' => '<img src=x onerror=alert(1)>']);

        $this->get('/jane/intro')
            ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', escape: false);
    }

    // --- Slots API ------------------------------------------------------

    public function test_slots_are_grouped_by_date_in_the_guests_time_zone(): void
    {
        $response = $this->getJson('/jane/intro/slots?month=2026-11&timezone=Asia/Tokyo')->assertOk();

        // Monday 09:00 UTC is Monday 18:00 in Tokyo; Monday 15:00 UTC is already Tuesday 00:00 there.
        $this->assertSame('2026-11-09T18:00:00+09:00', $response->json('days.2026-11-09.0'));
        $this->assertCount(12, $response->json('days.2026-11-09'));
        $this->assertSame('2026-11-10T00:00:00+09:00', $response->json('days.2026-11-10.0'));
        $this->assertNull($response->json('days.2026-11-01'));
    }

    public function test_existing_bookings_are_removed_from_slots(): void
    {
        Booking::factory()->at($this->eventType, '2026-11-09 10:00')->create();

        $slots = $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->json('days.2026-11-09');

        $this->assertContains('2026-11-09T09:30:00+00:00', $slots);
        $this->assertNotContains('2026-11-09T10:00:00+00:00', $slots);
        $this->assertContains('2026-11-09T10:30:00+00:00', $slots);
    }

    public function test_slots_request_is_validated(): void
    {
        $this->getJson('/jane/intro/slots?month=2026-13&timezone=UTC')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson('/jane/intro/slots?month=2026-11&timezone=Mars/Base')->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    public function test_slots_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->assertOk();
        }

        $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->assertTooManyRequests();
    }

    // --- Booking --------------------------------------------------------

    public function test_a_guest_can_book_a_slot(): void
    {
        Event::fake([BookingCreated::class]);
        $this->eventType->update(['buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
        $question = $this->eventType->questions()->create(['label' => 'Company', 'type' => 'text', 'is_required' => true, 'position' => 0]);
        $optIn = $this->eventType->questions()->create(['label' => 'Send me news', 'type' => 'checkbox', 'position' => 1]);

        $response = $this->post('/jane/intro', $this->form([
            // Submitted in the guest's zone: 04:00 New York is 09:00 UTC.
            'start' => '2026-11-09T04:00:00-05:00',
            'timezone' => 'America/New_York',
            'notes' => 'Looking forward to it',
            'answers' => [$question->id => 'Acme', $optIn->id => '0'],
        ]));

        $booking = Booking::sole();
        $response->assertRedirect("/bookings/{$booking->uid}");

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($this->host->id, $booking->host_id);
        $this->assertSame('2026-11-09 09:00:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 09:30:00', $booking->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 08:55:00', $booking->blocked_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 09:40:00', $booking->blocked_end_at->format('Y-m-d H:i:s'));
        $this->assertSame('America/New_York', $booking->guest_timezone);
        $this->assertSame('Looking forward to it', $booking->guest_notes);
        $this->assertSame(LocationType::GoogleMeet, $booking->location_type);
        $this->assertSame(
            [['Company', 'Acme'], ['Send me news', 'No']],
            $booking->answers->map(fn ($a) => [$a->label, $a->value])->all(),
        );

        Event::assertDispatched(BookingCreated::class, fn (BookingCreated $e) => $e->booking->is($booking)
            && hash('sha256', $e->manageToken) === $booking->manage_token_hash);
    }

    public function test_confirmation_page_shows_the_time_in_the_guests_zone(): void
    {
        $booking = Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create(['guest_timezone' => 'Asia/Tokyo']);

        $this->get("/bookings/{$booking->uid}")
            ->assertOk()
            ->assertSee('Intro call')
            ->assertSee('18:00 – 18:30')
            ->assertDontSee($booking->guest_email);

        $this->get('/bookings/01JNOTAREALBOOKINGULID0000')->assertNotFound();
    }

    public function test_a_taken_slot_cannot_be_booked_again(): void
    {
        Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create();

        $this->from('/jane/intro')->post('/jane/intro', $this->form())
            ->assertRedirect('/jane/intro')
            ->assertSessionHasErrors('start');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_cancelled_booking_frees_its_slot(): void
    {
        Booking::factory()->at($this->eventType, '2026-11-09 09:00')->cancelled()->create();

        $this->post('/jane/intro', $this->form())->assertSessionHasNoErrors();

        $this->assertSame(1, Booking::where('status', 'confirmed')->count());
    }

    public function test_another_event_types_booking_and_its_buffer_block_the_slot(): void
    {
        $other = EventType::factory()->for($this->host)->create(['duration_minutes' => 60, 'buffer_after_minutes' => 15]);
        Booking::factory()->at($other, '2026-11-09 08:00')->create(); // 08:00–09:00, buffer until 09:15

        $this->post('/jane/intro', $this->form())->assertSessionHasErrors('start');
        $this->post('/jane/intro', $this->form(['start' => '2026-11-09T09:30:00Z']))->assertSessionHasNoErrors();
    }

    public function test_times_that_are_not_offered_are_rejected(): void
    {
        $this->eventType->update(['min_notice_minutes' => 60]);

        foreach ([
            '2026-11-09T08:30:00Z', // before working hours
            '2026-11-09T09:10:00Z', // not on the slot grid
            '2026-11-07T10:00:00Z', // Saturday
            '2026-11-01T10:30:00Z', // inside minimum notice (and Sunday)
            '2027-06-01T10:00:00Z', // beyond the booking window
        ] as $start) {
            $this->post('/jane/intro', $this->form(['start' => $start]))->assertSessionHasErrors('start');
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_daily_limit_is_enforced(): void
    {
        $this->eventType->update(['daily_limit' => 1]);
        Booking::factory()->at($this->eventType, '2026-11-09 15:00')->create();

        $this->post('/jane/intro', $this->form())->assertSessionHasErrors('start');
        $this->post('/jane/intro', $this->form(['start' => '2026-11-10T09:00:00Z']))->assertSessionHasNoErrors();
    }

    public function test_busy_times_from_a_connected_calendar_block_slots(): void
    {
        $this->registerBusySource(fn () => [new Period('2026-11-09T09:00Z', '2026-11-09T10:00Z')]);

        $slots = $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->json('days.2026-11-09');
        $this->assertSame('2026-11-09T10:00:00+00:00', $slots[0]);

        $this->post('/jane/intro', $this->form())->assertSessionHasErrors('start');
    }

    public function test_an_unreadable_calendar_hides_all_slots(): void
    {
        $this->registerBusySource(fn () => throw new CalendarUnavailableException('token revoked'));

        $this->getJson('/jane/intro/slots?month=2026-11&timezone=UTC')->assertOk()->assertExactJson(['timezone' => 'UTC', 'days' => []]);
        $this->post('/jane/intro', $this->form())->assertSessionHasErrors('start');
        $this->assertSame(0, Booking::count());
    }

    // --- Validation and abuse -------------------------------------------

    public function test_booking_form_is_validated(): void
    {
        $this->eventType->update(['location_type' => LocationType::Phone]);
        $required = $this->eventType->questions()->create(['label' => 'Company', 'type' => 'text', 'is_required' => true, 'position' => 0]);
        $choice = $this->eventType->questions()->create(['label' => 'Size', 'type' => 'select', 'options' => ['1-10', '11+'], 'position' => 1]);
        $terms = $this->eventType->questions()->create(['label' => 'I agree', 'type' => 'checkbox', 'is_required' => true, 'position' => 2]);

        $this->post('/jane/intro', $this->form([
            'name' => '',
            'email' => 'not-an-email',
            'timezone' => 'Nowhere/Land',
            'start' => 'whenever',
            'guest_phone' => '',
            'answers' => [$choice->id => '1000+', $terms->id => '0'],
        ]))->assertSessionHasErrors([
            'name', 'email', 'timezone', 'start', 'guest_phone',
            "answers.{$required->id}", "answers.{$choice->id}", "answers.{$terms->id}",
        ]);

        $this->post('/jane/intro', $this->form([
            'guest_phone' => '+1 (555) 010-2030',
            'answers' => [$required->id => 'Acme', $choice->id => '11+', $terms->id => '1'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('+1 (555) 010-2030', Booking::sole()->guest_phone);
    }

    public function test_honeypot_rejects_bots(): void
    {
        $this->post('/jane/intro', $this->form(['website' => 'http://spam.example']))->assertSessionHasErrors('website');

        $this->assertSame(0, Booking::count());
    }

    public function test_booking_submissions_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/jane/intro', $this->form(['name' => '']));
        }

        $this->post('/jane/intro', $this->form())->assertTooManyRequests();
    }

    public function test_booking_form_is_csrf_protected(): void
    {
        // Laravel disables its CSRF check while unit tests run, so assert the
        // route sits in the "web" group (which applies it) and the form sends a token.
        $route = app('router')->getRoutes()->getByName('public.book.store');
        $this->assertContains('web', $route->gatherMiddleware());

        $this->get('/jane/intro')->assertSee('name="_token"', escape: false);
    }

    // --- Database backstop ----------------------------------------------

    public function test_database_rejects_two_confirmed_bookings_at_the_same_start(): void
    {
        Booking::factory()->at($this->eventType, '2026-11-09 09:00')->cancelled()->create();
        Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Booking::factory()->at($this->eventType, '2026-11-09 09:00')->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'start' => '2026-11-09T09:00:00Z',
            'timezone' => 'UTC',
            'name' => 'Grace Guest',
            'email' => 'grace@example.com',
        ], $overrides);
    }

    private function registerBusySource(\Closure $busy): void
    {
        $this->app->instance('test.busy-source', new class($busy) implements BusyTimeSource
        {
            public function __construct(private \Closure $busy) {}

            public function busyPeriods(User $host, Period $range): array
            {
                return ($this->busy)();
            }
        });
        $this->app->tag('test.busy-source', AppServiceProvider::BUSY_TIME_SOURCES);
    }
}
