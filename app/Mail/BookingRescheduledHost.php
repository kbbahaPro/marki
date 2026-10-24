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

class BookingRescheduledHost extends Mailable implements ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(
        public Booking $previous,
        public Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->booking->guest_email, $this->booking->guest_name)],
            subject: __('Rescheduled: :guest – :title, now :date', [
                'guest' => $this->booking->guest_name,
                'title' => $this->booking->eventType->title,
                'date' => $this->booking->start_at->setTimezone($this->booking->host->timezone)->translatedFormat('D, M j, H:i'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-rescheduled-host',
            text: 'emails.text.booking-rescheduled-host',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->host->timezone),
                'previousWhen' => $this->bookingTime($this->previous, $this->booking->host->timezone),
                'where' => $this->bookingPlace($this->booking),
                'inGoogleCalendar' => $this->booking->sync_status === SyncStatus::Synced,
            ],
        );
    }

    /**
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
