<?php

namespace Tests\Feature\Mail;

use App\Mail\UpcomingBookingReminder;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\EventType;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Host works Mon–Fri 09:00–17:00 UTC. "Now" is Sunday 2026-11-01 10:00 UTC;
 * the guest books Monday 2026-11-09 09:00 UTC with 24 h and 1 h reminders.
 */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();

        $host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host']);
        $this->eventType = EventType::factory()->for($host)->create(['title' => 'Intro call', 'reminder_offsets' => [1440, 60]]);
    }

    public function test_reminders_are_scheduled_from_the_event_types_offsets(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        $this->assertSame(
            [[1440, '2026-11-08 09:00:00'], [60, '2026-11-09 08:00:00']],
            $booking->reminders()->orderByDesc('offset_minutes')->get()
                ->map(fn ($r) => [$r->offset_minutes, $r->send_at->format('Y-m-d H:i:s')])->all(),
        );
    }

    public function test_reminders_whose_time_has_passed_are_not_scheduled(): void
    {
        // Booked 3 hours ahead: the 24-hour reminder would already be late.
        $this->travelTo('2026-11-09 06:00:00');
        $booking = $this->book('2026-11-09 09:00');

        $this->assertSame([60], $booking->reminders()->pluck('offset_minutes')->all());
    }

    public function test_due_reminders_are_sent_once(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        $this->artisan('bookings:send-reminders')->assertSuccessful();
        Mail::assertNotQueued(UpcomingBookingReminder::class);

        $this->travelTo('2026-11-08 09:00:30');
        $this->artisan('bookings:send-reminders');
        $this->artisan('bookings:send-reminders');

        Mail::assertQueued(UpcomingBookingReminder::class, 1);
        Mail::assertQueued(UpcomingBookingReminder::class, fn ($mail) => $mail->hasTo('grace@example.com') && $mail->booking->is($booking));
        $this->assertNotNull($booking->reminders()->where('offset_minutes', 1440)->value('sent_at'));

        $this->travelTo('2026-11-09 08:00:10');
        $this->artisan('bookings:send-reminders');
        Mail::assertQueued(UpcomingBookingReminder::class, 2);
    }

    public function test_a_reminder_claimed_by_another_run_is_not_sent_again(): void
    {
        $booking = $this->book('2026-11-09 09:00');
        $reminder = $booking->reminders()->where('offset_minutes', 1440)->sole();

        $this->travelTo('2026-11-08 09:00:30');
        $this->assertTrue($reminder->claim());
        $this->assertFalse($reminder->fresh()->claim());

        $this->artisan('bookings:send-reminders');
        Mail::assertNotQueued(UpcomingBookingReminder::class);
    }

    public function test_cancelling_removes_pending_reminders(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        app(BookingService::class)->cancel($booking, 'guest');

        $this->assertSame(0, $booking->reminders()->count());
        $this->travelTo('2026-11-09 08:30:00');
        $this->artisan('bookings:send-reminders');
        Mail::assertNotQueued(UpcomingBookingReminder::class);
    }

    public function test_rescheduling_moves_the_reminders(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        [$moved] = app(BookingService::class)->reschedule($booking, CarbonImmutable::parse('2026-11-10 14:00'));

        $this->assertSame(0, $booking->reminders()->count());
        $this->assertSame(
            ['2026-11-09 14:00:00', '2026-11-10 13:00:00'],
            $moved->reminders()->orderBy('send_at')->pluck('send_at')->map->format('Y-m-d H:i:s')->all(),
        );
    }

    public function test_stale_reminders_are_dropped_instead_of_sent(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        // The scheduler was down until after the meeting started.
        $this->travelTo('2026-11-09 09:10:00');
        $this->artisan('bookings:send-reminders');

        Mail::assertNotQueued(UpcomingBookingReminder::class);
        $this->assertSame(0, BookingReminder::count());
    }

    public function test_a_late_reminder_still_says_when_the_meeting_really_starts(): void
    {
        $this->book('2026-11-09 09:00');

        // The 24-hour reminder only goes out 22 hours before (scheduler outage).
        $this->travelTo('2026-11-08 11:00:00');
        $this->artisan('bookings:send-reminders');

        $mail = Mail::queued(UpcomingBookingReminder::class)->first();
        $mail->assertSeeInHtml('starts 22 hours from now');
    }

    public function test_reminder_email_has_the_guests_time_join_link_and_private_links(): void
    {
        $booking = $this->book('2026-11-09 09:00', timezone: 'Asia/Tokyo');
        $booking->forceFill(['meeting_url' => 'https://meet.google.com/abc-defg-hij'])->save();
        $token = $booking->manage_token;

        $this->travelTo('2026-11-09 08:00:30');
        $this->artisan('bookings:send-reminders');

        $mail = Mail::queued(UpcomingBookingReminder::class)->first();
        $this->assertInstanceOf(ShouldBeEncrypted::class, $mail);
        $mail->assertHasSubject('Reminder: Intro call with Jane Host at 18:00 (Mon, Nov 9)');
        $mail->assertSeeInHtml('starts 59 minutes from now');
        $mail->assertSeeInHtml('18:00 – 18:30');
        $mail->assertSeeInHtml('https://meet.google.com/abc-defg-hij');
        $mail->assertSeeInHtml(route('bookings.manage', $token));
        $mail->assertSeeInHtml(route('bookings.reschedule', $token));
    }

    public function test_the_manage_token_is_encrypted_at_rest_and_matches_its_hash(): void
    {
        $booking = $this->book('2026-11-09 09:00');

        $raw = DB::table('bookings')->where('id', $booking->id)->value('manage_token');
        $this->assertNotSame($booking->manage_token, $raw);
        $this->assertSame(hash('sha256', $booking->manage_token), $booking->manage_token_hash);
        $this->assertSame(64, strlen($booking->manage_token));
    }

    public function test_the_scheduler_runs_reminders_and_the_queue_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [preg_replace('/^.*artisan\S*\s+/', '', $event->command) => $event->expression]);

        $this->assertSame('* * * * *', $events['bookings:send-reminders']);
        $this->assertSame('* * * * *', $events['queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1']);
    }

    private function book(string $start, string $timezone = 'UTC'): Booking
    {
        [$booking] = app(BookingService::class)->book(
            $this->eventType,
            CarbonImmutable::parse($start),
            new GuestDetails('Grace Guest', 'grace@example.com', $timezone),
        );

        return $booking->fresh();
    }
}
