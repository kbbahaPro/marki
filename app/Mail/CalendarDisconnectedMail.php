<?php

namespace App\Mail;

use App\Models\CalendarConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarDisconnectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public CalendarConnection $calendarConnection) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Action needed: reconnect your Google Calendar'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.calendar-disconnected',
            with: [
                'url' => route('settings.calendar'),
                'accountEmail' => $this->calendarConnection->account_email,
            ],
        );
    }
}
