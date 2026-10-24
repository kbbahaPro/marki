<?php

namespace Tests\Feature\Mail;

use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Ics\IcsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IcsGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        config(['app.name' => 'Acme Scheduler', 'app.url' => 'https://book.example.com']);

        $host = User::factory()->create(['name' => 'Jane "JJ" Host', 'email' => 'jane@example.com']);
        $eventType = EventType::factory()->for($host)->create(['title' => 'Intro, call; v2']);
        $this->booking = Booking::factory()->at($eventType, '2026-11-09 09:00')->create([
            'guest_name' => 'Grace Guest',
            'guest_email' => 'grace@example.com',
            'guest_notes' => "Line one\nLine two, with comma; and semicolon \\ backslash",
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ]);
    }

    public function test_it_produces_a_valid_request_invitation(): void
    {
        $ics = app(IcsGenerator::class)->forBooking($this->booking);
        $lines = $this->unfold($ics);

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', end($lines));
        $this->assertContains('METHOD:REQUEST', $lines);
        $this->assertContains('STATUS:CONFIRMED', $lines);
        $this->assertContains("UID:{$this->booking->uid}@book.example.com", $lines);
        $this->assertContains('SEQUENCE:0', $lines);
        $this->assertContains('DTSTAMP:20261101T100000Z', $lines);
        $this->assertContains('DTSTART:20261109T090000Z', $lines);
        $this->assertContains('DTEND:20261109T093000Z', $lines);
        $this->assertContains('ORGANIZER;CN="Jane \'JJ\' Host":mailto:jane@example.com', $lines);
        $this->assertContains('ATTENDEE;CN="Grace Guest";ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:grace@example.com', $lines);
        $this->assertContains('LOCATION:https://meet.google.com/abc-defg-hij', $lines);
        $this->assertContains('URL:https://meet.google.com/abc-defg-hij', $lines);
    }

    public function test_text_values_are_escaped(): void
    {
        $lines = $this->unfold(app(IcsGenerator::class)->forBooking($this->booking));

        $this->assertContains('SUMMARY:Intro\, call\; v2 – Jane "JJ" Host & Grace Guest', $lines);

        $description = collect($lines)->first(fn ($l) => str_starts_with($l, 'DESCRIPTION:'));
        $this->assertStringContainsString('Line one\nLine two\, with comma\; and semicolon \\\\ backslash', $description);
        $this->assertStringNotContainsString("\n", $description);
    }

    public function test_lines_use_crlf_and_are_folded_at_75_octets(): void
    {
        $this->booking->update(['guest_notes' => str_repeat('Réservation très longue ', 20)]);

        $ics = app(IcsGenerator::class)->forBooking($this->booking);

        $this->assertStringEndsWith("\r\n", $ics);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $ics));

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Line too long: {$line}");
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'A multi-byte character was split across lines.');
        }

        // Unfolding restores the original text.
        $description = collect($this->unfold($ics))->first(fn ($l) => str_starts_with($l, 'DESCRIPTION:'));
        $this->assertStringContainsString(str_repeat('Réservation très longue ', 20), $description);
    }

    public function test_cancellation_uses_the_same_uid_with_a_higher_sequence(): void
    {
        $generator = app(IcsGenerator::class);
        $lines = $this->unfold($generator->forBooking($this->booking, IcsGenerator::CANCEL, 1));

        $this->assertContains('METHOD:CANCEL', $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
        $this->assertContains('SEQUENCE:1', $lines);
        $this->assertContains('UID:'.$generator->uid($this->booking), $lines);
    }

    /**
     * @return list<string>
     */
    private function unfold(string $ics): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }
}
