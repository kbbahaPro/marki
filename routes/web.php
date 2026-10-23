<?php

use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\BookingConfirmationController;
use App\Http\Controllers\Public\BookingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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

    Route::resource('event-types', EventTypeController::class)
        ->except('show')
        ->parameters(['event-types' => 'eventType']);
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('team')->name('team.')->group(function () {
    Route::get('/', [TeamController::class, 'index'])->name('index');
    Route::post('/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('invite');
    Route::delete('/invitations/{invitation}', [TeamController::class, 'revoke'])->name('invitations.revoke');
});

require __DIR__.'/auth.php';

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

Route::pattern('username', '[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]');
Route::pattern('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');

Route::get('/{username}', [BookingPageController::class, 'profile'])->name('public.profile');
Route::get('/{username}/{slug}', [BookingPageController::class, 'show'])->name('public.book');
Route::get('/{username}/{slug}/slots', [BookingPageController::class, 'slots'])
    ->middleware('throttle:booking-slots')
    ->name('public.slots');
Route::post('/{username}/{slug}', [BookingPageController::class, 'store'])
    ->middleware('throttle:booking-submit')
    ->name('public.book.store');
