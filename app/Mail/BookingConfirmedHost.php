<?php

namespace App\Mail;

use App\Enums\SyncStatus;
use App\Mail\Concerns\DescribesBooking;
use App\Models\Booking;
use App\Services\Ics\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the host who booked and what they answered.
 */
class BookingConfirmedHost extends Mailable implements ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        $start = $this->booking->start_at->setTimezone($this->booking->host->timezone);

        return new Envelope(
            replyTo: [new Address($this->booking->guest_email, $this->booking->guest_name)],
            subject: __('New booking: :guest – :title on :date', [
                'guest' => $this->booking->guest_name,
                'title' => $this->booking->eventType->title,
                'date' => $start->translatedFormat('D, M j, H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        $this->booking->loadMissing('answers');

        return new Content(
            view: 'emails.booking-confirmed-host',
            text: 'emails.text.booking-confirmed-host',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->host->timezone),
                'where' => $this->bookingPlace($this->booking),
                'inGoogleCalendar' => $this->booking->sync_status === SyncStatus::Synced,
            ],
        );
    }

    /**
     * Only when the event is not already in the host's Google Calendar:
     * attaching it again could create a duplicate entry there.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->booking->sync_status === SyncStatus::Synced) {
            return [];
        }

        return [
            Attachment::fromData(fn () => app(IcsGenerator::class)->forBooking($this->booking), 'booking.ics')
                ->withMime('text/calendar; charset=utf-8; method=REQUEST'),
        ];
    }
}
