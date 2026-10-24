<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Mail\UpcomingBookingReminder;
use App\Models\BookingReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Queues reminder emails that are due. Runs every minute from the scheduler;
 * each reminder is claimed atomically, so overlapping runs never send twice.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders {--limit=200 : Maximum reminders to process per run}';

    protected $description = 'Queue reminder emails for upcoming bookings';

    public function handle(): int
    {
        $sent = 0;
        $skipped = 0;

        $due = BookingReminder::query()
            ->due()
            ->with(['booking.eventType', 'booking.host'])
            ->orderBy('send_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $reminder) {
            $booking = $reminder->booking;

            // Cancelled, moved or already started: a reminder would only confuse.
            if ($booking->status !== BookingStatus::Confirmed || ! $booking->start_at->isFuture()) {
                $reminder->delete();
                $skipped++;

                continue;
            }

            if (! $reminder->claim()) {
                continue;
            }

            Mail::to($booking->guest_email, $booking->guest_name)->send(new UpcomingBookingReminder($booking));
            $sent++;
        }

        $this->components->info("Queued {$sent} reminder(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
