<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\View\View;

class BookingConfirmationController extends Controller
{
    /**
     * The page a guest lands on after booking. The URL contains the booking's
     * random ULID, and the page shows no contact details.
     */
    public function __invoke(Booking $booking): View
    {
        return view('public.confirmation', [
            'booking' => $booking->load(['eventType', 'host', 'hosts']),
        ]);
    }
}
