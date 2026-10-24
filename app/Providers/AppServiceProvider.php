<?php

namespace App\Providers;

use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\Google\GoogleApi;
use App\Services\Calendar\Google\GoogleCalendarService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\SlotEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Public booking endpoints are unauthenticated, so limit them per IP.
        RateLimiter::for('booking-slots', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('booking-submit', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
