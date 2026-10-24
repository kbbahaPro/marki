<?php

use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\TeamAdminController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\BookingConfirmationController;
use App\Http\Controllers\Public\BookingPageController;
use App\Http\Controllers\Public\ManageBookingController;
use App\Http\Controllers\Public\TeamPageController;
use App\Http\Controllers\Settings\CalendarController;
use App\Http\Controllers\TeamEventTypeController;
use App\Mail\BookingConfirmedGuest;
use App\Mail\BookingConfirmedHost;
use App\Mail\MeetingLinkReady;
use App\Models\Booking;
use App\Services\Ics\IcsGenerator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth', 'verified'])->prefix('dashboard')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('/bookings/{booking}')->name('dashboard.bookings.')->group(function () {
        Route::get('/', [DashboardController::class, 'show'])->name('show');
        Route::post('/cancel', [DashboardController::class, 'cancel'])->name('cancel');
        Route::get('/reschedule', [DashboardController::class, 'editTime'])->name('reschedule');
        Route::get('/reschedule/slots', [DashboardController::class, 'slots'])->name('reschedule.slots');
        Route::post('/reschedule', [DashboardController::class, 'reschedule'])->name('reschedule.store');
        Route::post('/sync', [DashboardController::class, 'retrySync'])->middleware('throttle:10,1')->name('sync');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/availability', [AvailabilityController::class, 'edit'])->name('availability.edit');
    Route::put('/availability', [AvailabilityController::class, 'update'])->name('availability.update');
    Route::post('/availability/overrides', [AvailabilityController::class, 'storeOverride'])->name('availability.overrides.store');
    Route::delete('/availability/overrides/{date}', [AvailabilityController::class, 'destroyOverride'])->name('availability.overrides.destroy');

    Route::get('/settings/calendar', [CalendarController::class, 'show'])->name('settings.calendar');
    Route::post('/settings/calendar/google', [CalendarController::class, 'connect'])->name('settings.calendar.connect');
    Route::get('/settings/calendar/google/callback', [CalendarController::class, 'callback'])->name('settings.calendar.callback');
    Route::delete('/settings/calendar/google', [CalendarController::class, 'disconnect'])->name('settings.calendar.disconnect');

    Route::resource('event-types', EventTypeController::class)
        ->except('show')
        ->parameters(['event-types' => 'eventType']);

    // Scoped: the event type must belong to the team in the URL.
    Route::resource('teams.event-types', TeamEventTypeController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])
        ->parameters(['event-types' => 'eventType'])
        ->scoped();
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->group(function () {
    Route::prefix('members')->name('members.')->group(function () {
        Route::get('/', [MemberController::class, 'index'])->name('index');
        Route::post('/invitations', [MemberController::class, 'invite'])->middleware('throttle:20,1')->name('invite');
        Route::delete('/invitations/{invitation}', [MemberController::class, 'revoke'])->name('invitations.revoke');
    });

    Route::prefix('teams')->name('admin.teams.')->group(function () {
        Route::get('/', [TeamAdminController::class, 'index'])->name('index');
        Route::post('/', [TeamAdminController::class, 'store'])->name('store');
        Route::get('/{team}', [TeamAdminController::class, 'edit'])->name('edit');
        Route::put('/{team}', [TeamAdminController::class, 'update'])->name('update');
        Route::delete('/{team}', [TeamAdminController::class, 'destroy'])->name('destroy');
        Route::post('/{team}/members', [TeamAdminController::class, 'addMember'])->name('members.store');
        Route::patch('/{team}/members/{user}', [TeamAdminController::class, 'updateMember'])->name('members.update');
        Route::delete('/{team}/members/{user}', [TeamAdminController::class, 'removeMember'])->name('members.destroy');
    });
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Local email previews
|--------------------------------------------------------------------------
|
| Renders booking emails for the most recent booking. Only registered when
| APP_ENV=local, so it never exists in production.
|
*/

if (app()->environment('local')) {
    Route::get('/_preview/mail/{mail}', function (string $mail) {
        $booking = Booking::latest('id')->firstOrFail();

        return match ($mail) {
            'guest' => new BookingConfirmedGuest($booking, 'preview-token'),
            'host' => new BookingConfirmedHost($booking),
            'meeting-link' => new MeetingLinkReady($booking),
            'ics' => response(app(IcsGenerator::class)->forBooking($booking))->header('Content-Type', 'text/plain; charset=utf-8'),
            default => abort(404),
        };
    })->middleware('auth');

    // Guest-only screens, viewable while signed in, for design work.
    Route::get('/_preview/page/{page}', fn (string $page) => match ($page) {
        'login' => view('auth.login'),
        'forgot-password' => view('auth.forgot-password'),
        default => abort(404),
    });
}

/*
|--------------------------------------------------------------------------
| Public booking pages
|--------------------------------------------------------------------------
|
| Registered last so they never shadow application routes. Usernames that
| would collide are reserved in config/booking.php.
|
*/

Route::get('/bookings/{booking}', BookingConfirmationController::class)->name('public.bookings.show');

Route::prefix('/manage/{token}')->where(['token' => '[A-Za-z0-9]{64}'])->name('bookings.')->group(function () {
    Route::get('/', [ManageBookingController::class, 'show'])->middleware('throttle:booking-slots')->name('manage');
    Route::post('/cancel', [ManageBookingController::class, 'cancel'])->middleware('throttle:booking-submit')->name('cancel');
    Route::get('/reschedule', [ManageBookingController::class, 'editTime'])->middleware('throttle:booking-slots')->name('reschedule');
    Route::get('/reschedule/slots', [ManageBookingController::class, 'slots'])->middleware('throttle:booking-slots')->name('reschedule.slots');
    Route::post('/reschedule', [ManageBookingController::class, 'reschedule'])->middleware('throttle:booking-submit')->name('reschedule.store');
});

Route::get('/team/{team}', [TeamPageController::class, 'show'])->name('public.team');

Route::pattern('username', '[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]');
Route::pattern('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');

// "team" is a reserved username, so these never shadow a person's pages.
Route::get('/team/{team}/{slug}', [TeamPageController::class, 'book'])->name('public.team.book');
Route::get('/team/{team}/{slug}/slots', [TeamPageController::class, 'slots'])
    ->middleware('throttle:booking-slots')
    ->name('public.team.slots');
Route::post('/team/{team}/{slug}', [BookingPageController::class, 'store'])
    ->middleware('throttle:booking-submit')
    ->name('public.team.book.store');

Route::get('/{username}', [BookingPageController::class, 'profile'])->name('public.profile');
Route::get('/{username}/{slug}', [BookingPageController::class, 'show'])->name('public.book');
Route::get('/{username}/{slug}/slots', [BookingPageController::class, 'slots'])
    ->middleware('throttle:booking-slots')
    ->name('public.slots');
Route::post('/{username}/{slug}', [BookingPageController::class, 'store'])
    ->middleware('throttle:booking-submit')
    ->name('public.book.store');
