<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingRescheduled;
use App\Listeners\CreateCalendarEvent;
use App\Listeners\ScheduleReminders;
use App\Listeners\SendBookingConfirmations;
use App\Listeners\SendChangeEmails;
use App\Listeners\UpdateCalendarAfterChange;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\Google\GoogleApi;
use App\Services\Calendar\Google\GoogleCalendarService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\SlotEngine;
use App\Support\BrandPalette;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Tag that external calendar integrations (BusyTimeSource) register under.
     */
    public const BUSY_TIME_SOURCES = 'busy-time-sources';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleApi::class, fn () => new GoogleApi(config('services.google')));
        $this->app->bind(CalendarProvider::class, GoogleCalendarService::class);
        $this->app->tag([GoogleCalendarService::class], self::BUSY_TIME_SOURCES);

        $this->app->bind(AvailabilityService::class, fn ($app) => new AvailabilityService(
            $app->make(SlotEngine::class),
            $app->tagged(self::BUSY_TIME_SOURCES),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Order matters: the calendar event (and its Meet link) must exist
        // before the confirmation emails are built.
        Event::listen(BookingCreated::class, CreateCalendarEvent::class);
        Event::listen(BookingCreated::class, SendBookingConfirmations::class);

        Event::listen([BookingCancelled::class, BookingRescheduled::class], UpdateCalendarAfterChange::class);
        Event::listen([BookingCancelled::class, BookingRescheduled::class], SendChangeEmails::class);

        Event::listen([BookingCreated::class, BookingRescheduled::class, BookingCancelled::class], ScheduleReminders::class);

        // Emails use inline styles, so they get the (contrast-safe) brand colour as a value.
        View::composer('emails.*', fn ($view) => $view->with('brandPrimary', BrandPalette::fromConfig()->primaryHex()));

        // Public booking endpoints are unauthenticated, so limit them per IP.
        RateLimiter::for('booking-slots', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('booking-submit', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
