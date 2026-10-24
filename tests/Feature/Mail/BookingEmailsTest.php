<?php

namespace Tests\Feature\Mail;

use App\Enums\SyncStatus;
use App\Jobs\SyncBookingToCalendar;
use App\Mail\BookingConfirmedGuest;
use App\Mail\BookingConfirmedHost;
use App\Mail\MeetingLinkReady;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/**
 * Host in Europe/Paris working Mon–Fri 09:00–17:00 Paris time; "now" is
 * 2026-11-01. A Monday 9 November 10:00 Paris booking is 09:00 UTC.
 */
class BookingEmailsTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private User $host;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        Mail::fake();
        $this->fakeGoogle();

        $this->host = User::factory()->create(['username' => 'jane', 'name' => 'Jane Host', 'email' => 'jane@example.com', 'timezone' => 'Europe/Paris']);
        $this->eventType = EventType::factory()->for($this->host)->create(['slug' => 'intro', 'title' => 'Intro call']);
    }

    public function test_guest_and_host_are_emailed_after_booking(): void
    {
        $this->book();

        $booking = Booking::sole();

        Mail::assertQueued(BookingConfirmedGuest::class, function (BookingConfirmedGuest $mail) use ($booking) {
            return $mail->hasTo('grace@example.com')
                && $mail->hasReplyTo('jane@example.com')
                && $mail->booking->is($booking)
                && hash('sha256', $mail->manageToken) === $booking->manage_token_hash;
        });

        Mail::assertQueued(BookingConfirmedHost::class, fn (BookingConfirmedHost $mail) => $mail->hasTo('jane@example.com')
            && $mail->hasReplyTo('grace@example.com'));

        Mail::assertQueuedCount(2);
    }

    public function test_guest_email_is_encrypted_while_queued_because_it_holds_the_manage_token(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new BookingConfirmedGuest(Booking::factory()->make(), 'token'));
    }

    public function test_guest_email_shows_the_guests_time_and_attaches_an_invitation(): void
    {
        $this->book(['timezone' => 'America/New_York']);
        $mail = $this->queued(BookingConfirmedGuest::class);

        $mail->assertHasSubject('Confirmed: Intro call with Jane Host on Mon, Nov 9, 04:00');
        $mail->assertSeeInHtml('Monday, November 9, 2026');
        $mail->assertSeeInHtml('04:00 – 04:30');
        $mail->assertSeeInHtml('America/New York');
        $mail->assertSeeInText('04:00 – 04:30');

        $attachment = $mail->attachments()[0];
        $this->assertSame('invite.ics', $attachment->as);
        $this->assertSame('text/calendar; charset=utf-8; method=REQUEST', $attachment->mime);
    }

    public function test_host_email_shows_the_hosts_time_and_the_guests_answers(): void
    {
        $question = $this->eventType->questions()->create(['label' => 'Company', 'type' => 'text', 'position' => 0]);

        $this->book(['timezone' => 'America/New_York', 'notes' => 'See you', 'answers' => [$question->id => 'Acme & Co']]);
        $mail = $this->queued(BookingConfirmedHost::class);

        $mail->assertHasSubject('New booking: Grace Guest – Intro call on Mon, Nov 9, 10:00');
        $mail->assertSeeInHtml('10:00 – 10:30');
        $mail->assertSeeInHtml('Europe/Paris');
        $mail->assertSeeInHtml('grace@example.com');
        $mail->assertSeeInHtml('Acme &amp; Co', escape: false);
        $mail->assertSeeInText('Company: Acme & Co');
        $mail->assertSeeInHtml('See you');
    }

    public function test_guest_content_is_escaped_in_the_host_email(): void
    {
        $this->book(['name' => '<script>alert(1)</script> [click](http://evil.example)', 'notes' => '<img src=x onerror=alert(1)>']);
        $mail = $this->queued(BookingConfirmedHost::class);

        $html = $mail->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="http://evil.example"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_meet_link_from_google_is_in_the_confirmation(): void
    {
        $this->connectGoogle($this->host);
        $this->queueGoogle(
            $this->freeBusyResponse(),
            $this->googleJson(['id' => 'e1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']),
        );

        $this->book();

        $guest = $this->queued(BookingConfirmedGuest::class);
        $guest->assertSeeInHtml('https://meet.google.com/abc-defg-hij');
        $guest->assertSeeInHtml('Join the meeting');

        // Already in the host's Google Calendar: no .ics that could duplicate it.
        $host = $this->queued(BookingConfirmedHost::class);
        $host->assertSeeInHtml('added to your Google Calendar');
        $this->assertSame([], $host->attachments());
    }

    public function test_hosts_without_google_get_an_ics_and_guests_are_told_the_host_sends_the_link(): void
    {
        $this->book();

        $host = $this->queued(BookingConfirmedHost::class);
        $this->assertSame('booking.ics', $host->attachments()[0]->as);

        $this->queued(BookingConfirmedGuest::class)->assertSeeInHtml('Jane Host will send you the link');
    }

    public function test_confirmations_are_sent_even_if_an_inline_retry_also_fails(): void
    {
        // Tests use the "sync" queue, so the retry job runs immediately and fails again.
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse(), $this->googleError(503), $this->googleError(503));

        $this->book();

        $this->assertSame(SyncStatus::Failed, Booking::sole()->sync_status);
        Mail::assertQueued(BookingConfirmedGuest::class);
        Mail::assertQueued(BookingConfirmedHost::class);
    }

    public function test_a_late_meet_link_is_sent_when_the_retry_succeeds(): void
    {
        Queue::fake([SyncBookingToCalendar::class]);
        $this->connectGoogle($this->host);
        $this->queueGoogle($this->freeBusyResponse(), $this->googleError(503));

        $this->book();

        $booking = Booking::sole();
        $this->assertSame(SyncStatus::Failed, $booking->sync_status);
        $this->queued(BookingConfirmedGuest::class)->assertSeeInHtml('the link will follow in a separate email');

        Queue::assertPushed(SyncBookingToCalendar::class);

        // Run the queued retry (Queue::fake intercepts dispatchSync too).
        $this->queueGoogle($this->googleJson(['id' => 'e1', 'hangoutLink' => 'https://meet.google.com/late-link']));
        app()->call([new SyncBookingToCalendar($booking), 'handle']);

        Mail::assertQueued(MeetingLinkReady::class, fn (MeetingLinkReady $mail) => $mail->hasTo('grace@example.com')
            && str_contains($mail->render(), 'https://meet.google.com/late-link'));
    }

    public function test_phone_and_custom_locations_are_described(): void
    {
        $this->eventType->update(['location_type' => 'phone']);
        $this->book(['guest_phone' => '+33 1 23 45 67 89']);
        $this->queued(BookingConfirmedGuest::class)->assertSeeInHtml('Jane Host will call you at +33 1 23 45 67 89.');

        $this->eventType->update(['location_type' => 'custom', 'location_value' => '1 Rue de Rivoli, Paris']);
        $this->book(['start' => '2026-11-09T10:00:00Z']);
        Mail::assertQueued(BookingConfirmedGuest::class, fn ($mail) => str_contains($mail->render(), '1 Rue de Rivoli, Paris'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = []): void
    {
        $this->post('/jane/intro', array_merge([
            'start' => '2026-11-09T09:00:00Z',
            'timezone' => 'UTC',
            'name' => 'Grace Guest',
            'email' => 'grace@example.com',
        ], $overrides))->assertSessionHasNoErrors();
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function queued(string $class): mixed
    {
        return Mail::queued($class)->last();
    }
}
