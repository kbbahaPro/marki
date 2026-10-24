<?php

namespace App\Mail;

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

class BookingCancelledGuest extends Mailable implements ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->booking->host->email, $this->booking->host->name)],
            subject: __('Cancelled: :title with :host on :date', [
                'title' => $this->booking->eventType->title,
                'host' => $this->booking->host->name,
                'date' => $this->booking->start_at->setTimezone($this->booking->guest_timezone)->translatedFormat('D, M j, H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        $eventType = $this->booking->eventType;

        return new Content(
            view: 'emails.booking-cancelled-guest',
            text: 'emails.text.booking-cancelled-guest',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->guest_timezone),
                'byHost' => $this->booking->cancelled_by === 'host',
                'bookAgainUrl' => $eventType->is_active && ! $eventType->trashed()
                    ? route('public.book', [$this->booking->host->username, $eventType->slug])
                    : null,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(IcsGenerator::class)->forBooking($this->booking, IcsGenerator::CANCEL), 'cancel.ics')
                ->withMime('text/calendar; charset=utf-8; method=CANCEL'),
        ];
    }
}
