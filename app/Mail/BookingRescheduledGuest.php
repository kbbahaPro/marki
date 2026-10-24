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
 * Carries the new booking's manage token, so it is encrypted while queued.
 * The .ics reuses the original UID with a higher SEQUENCE, which moves the
 * event in the guest's calendar instead of adding a second one.
 */
class BookingRescheduledGuest extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    /**
     * @param  'guest'|'host'  $by
     */
    public function __construct(
        public Booking $previous,
        public Booking $booking,
        public string $manageToken,
        public string $by = 'guest',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->booking->host->email, $this->booking->host->name)],
            subject: __('Rescheduled: :title with :host on :date', [
                'title' => $this->booking->eventType->title,
                'host' => $this->booking->host->name,
                'date' => $this->booking->start_at->setTimezone($this->booking->guest_timezone)->translatedFormat('D, M j, H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-rescheduled-guest',
            text: 'emails.text.booking-rescheduled-guest',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->guest_timezone),
                'previousWhen' => $this->bookingTime($this->previous, $this->booking->guest_timezone),
                'where' => $this->bookingPlace($this->booking),
                'manageUrl' => route('bookings.manage', $this->manageToken),
                'rescheduleUrl' => route('bookings.reschedule', $this->manageToken),
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
