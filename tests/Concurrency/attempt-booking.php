<?php

/*
 * Child process for ConcurrentBookingTest. Boots the application against the
 * MySQL test database, waits for a shared start instant so it races its
 * sibling process, then tries to book.
 *
 * Usage: php attempt-booking.php <eventTypeId> <startIso> <goAtUnixFloat>
 * Prints "BOOKED <id> <hostId>" or "UNAVAILABLE".
 */

use App\Exceptions\SlotUnavailableException;
use App\Models\EventType;
use App\Services\Booking\BookingService;
use App\Services\Booking\GuestDetails;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $eventTypeId, $start, $goAt] = $argv;

// Do all the slow work (autoloading, connecting, loading models) before the start line.
$eventType = EventType::with('user')->findOrFail($eventTypeId);
$bookings = $app->make(BookingService::class);
DB::select('select 1');

while (microtime(true) < (float) $goAt) {
    usleep(200);
}

try {
    [$booking] = $bookings->book(
        $eventType,
        CarbonImmutable::parse($start),
        new GuestDetails('Racer '.getmypid(), 'racer'.getmypid().'@example.com', 'UTC'),
        now: CarbonImmutable::parse('2026-11-01T00:00:00Z'),
    );

    echo "BOOKED {$booking->id} {$booking->host_id}";
} catch (SlotUnavailableException) {
    echo 'UNAVAILABLE';
}
