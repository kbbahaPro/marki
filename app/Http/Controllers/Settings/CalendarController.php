<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Services\Calendar\Google\GoogleApi;
use App\Services\Calendar\Google\GoogleCalendarService;
use Google\Exception as GoogleException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __construct(
        private readonly GoogleApi $google,
        private readonly GoogleCalendarService $calendar,
    ) {}

    public function show(Request $request): View
    {
        return view('settings.calendar', [
            'connection' => $request->user()->googleConnection,
            'configured' => $this->google->isConfigured(),
        ]);
    }

    /**
     * Send the host to Google's consent screen.
     */
    public function connect(Request $request): RedirectResponse
    {
        abort_unless($this->google->isConfigured(), 503, __('Google sign-in is not configured on this server.'));

        // CSRF protection for the OAuth round trip.
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->google->authUrl($state));
    }

    /**
     * Google redirects back here with an authorization code.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->fail(__('The Google sign-in session expired. Please try again.'));
        }

        if ($request->filled('error')) {
            return $this->fail(__('Google Calendar was not connected (:error).', ['error' => Str::limit((string) $request->query('error'), 50)]));
        }

        try {
            $token = $this->google->exchangeCode((string) $request->query('code'));
        } catch (GuzzleException|GoogleException|\InvalidArgumentException $e) {
            report($e);

            return $this->fail(__('Could not reach Google. Please try again.'));
        }

        if (isset($token['error']) || empty($token['access_token']) || empty($token['refresh_token'])) {
            return $this->fail(__('Google did not grant access. Please try again.'));
        }

        // Google's consent screen lets people untick individual permissions.
        $granted = explode(' ', $token['scope'] ?? '');
        if (array_diff(array_slice(GoogleApi::SCOPES, 2), $granted) !== []) {
            return $this->fail(__('Please allow both calendar permissions so we can check availability and add bookings to your calendar.'));
        }

        try {
            $email = $this->google->accountEmail($token['access_token']);
        } catch (GuzzleException|GoogleException $e) {
            report($e);

            return $this->fail(__('Could not read your Google account. Please try again.'));
        }

        $user = $request->user();
        $user->googleConnection()->updateOrCreate(['provider' => CalendarConnection::GOOGLE], [
            'account_email' => $email,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes' => $token['scope'],
            'status' => ConnectionStatus::Active,
            'last_error' => null,
        ]);

        $this->calendar->forgetBusyTimes($user);

        return redirect()->route('settings.calendar')->with('status', 'calendar-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $connection = $request->user()->googleConnection;

        if ($connection) {
            try {
                // Best effort: the local connection is removed either way.
                $this->google->revoke($connection->refresh_token);
            } catch (GuzzleException|GoogleException $e) {
                report($e);
            }

            $connection->delete();
            $this->calendar->forgetBusyTimes($request->user());
        }

        return redirect()->route('settings.calendar')->with('status', 'calendar-disconnected');
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('settings.calendar')->with('error', $message);
    }
}
