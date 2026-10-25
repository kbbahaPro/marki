<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

/**
 * Marks confirmed bookings as completed once they have been over for a
 * while (config booking.auto_complete_after_minutes). Hosts who saw a
 * no-show can still switch it afterwards.
 */
class CompleteBookings extends Command
{
    protected $signature = 'bookings:complete {--limit=200 : Maximum bookings to process per run}';

    protected $description = 'Mark bookings that ended a while ago as completed';

    public function handle(BookingService $bookings): int
    {
        $cutoff = now()->subMinutes(max(0, (int) config('booking.auto_complete_after_minutes')));
        $completed = 0;

        $due = Booking::query()
            ->confirmed()
            ->where('end_at', '<=', $cutoff)
            ->orderBy('end_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $booking) {
            if ($bookings->recordOutcome($booking, BookingStatus::Completed, onlyIfConfirmed: true)) {
                $completed++;
            }
        }

        $this->components->info("Marked {$completed} booking(s) as completed.");

        return self::SUCCESS;
    }
}
