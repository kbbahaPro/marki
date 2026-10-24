<?php

namespace App\Mail;

use App\Mail\Concerns\DescribesBooking;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the guest before the meeting. Encrypted while queued because the
 * rendered email contains the guest's private manage links.
 */
class UpcomingBookingReminder extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use DescribesBooking, Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        $start = $this->booking->start_at->setTimezone($this->booking->guest_timezone);

        return new Envelope(
            replyTo: [new Address($this->booking->host->email, $this->booking->host->name)],
            subject: __('Reminder: :title with :host at :time (:date)', [
                'title' => $this->booking->eventType->title,
                'host' => $this->booking->hostNames(),
                'time' => $start->format('H:i'),
                'date' => $start->translatedFormat('D, M j'),
            ]),
        );
    }

    public function content(): Content
    {
        $token = $this->booking->manage_token;

        return new Content(
            view: 'emails.booking-reminder',
            text: 'emails.text.booking-reminder',
            with: [
                'when' => $this->bookingTime($this->booking, $this->booking->guest_timezone),
                'where' => $this->bookingPlace($this->booking),
                // Rendered at send time, so "in 1 hour" stays true even if the queue ran late.
                'startsIn' => $this->booking->start_at->diffForHumans(['parts' => 2, 'join' => true, 'minimumUnit' => 'minute']),
                'manageUrl' => $token ? route('bookings.manage', $token) : null,
                'rescheduleUrl' => $token ? route('bookings.reschedule', $token) : null,
            ],
        );
    }
}
