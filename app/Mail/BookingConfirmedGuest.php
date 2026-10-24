<?php

namespace App\Mail;

use App\Mail\Concerns\DescribesBooking;
use App\Models\Booking;
use App\Services\Ics\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the guest with a calendar invitation. Encrypted while queued
 * because it carries the guest's private cancel/reschedule token.
 */
class BookingConfirmedGuest extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $manageToken,
    ) {}

    public function envelope(): Envelope
    {
        $start = $this->booking->start_at->setTimezone($this->booking->guest_timezone);

        return new Envelope(
            replyTo: [new Address($this->booking->host->email, $this->booking->host->name)],
            subject: __('Confirmed: :title with :host on :date', [
                'title' => $this->booking->eventType->title,
                'host' => $this->booking->host->name,
                'date' => $start->translatedFormat('D, M j, H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmed-guest',
            text: 'emails.text.booking-confirmed-guest',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->guest_timezone),
                'where' => $this->bookingPlace($this->booking),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(IcsGenerator::class)->forBooking($this->booking), 'invite.ics')
                ->withMime('text/calendar; charset=utf-8; method=REQUEST'),
        ];
    }
}
