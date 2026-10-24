<?php

namespace App\Mail;

use App\Mail\Concerns\DescribesBooking;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Follows a confirmation that went out before the Meet link existed
 * (the calendar event was created on a later retry).
 */
class MeetingLinkReady extends Mailable implements ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->booking->host->email, $this->booking->host->name)],
            subject: __('Meeting link: :title with :host', [
                'title' => $this->booking->eventType->title,
                'host' => $this->booking->host->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.meeting-link-ready',
            text: 'emails.text.meeting-link-ready',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->guest_timezone),
            ],
        );
    }
}
