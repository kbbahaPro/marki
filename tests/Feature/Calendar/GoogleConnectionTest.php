<?php

namespace Tests\Feature\Calendar;

use App\Enums\ConnectionStatus;
use App\Models\User;
use App\Services\Calendar\Google\GoogleApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class GoogleConnectionTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

    private const GRANTED = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->fakeGoogle();
    }

    public function test_settings_page_offers_to_connect(): void
    {
        $this->actingAs($this->user)->get('/settings/calendar')->assertOk()->assertSee('Connect Google Calendar');
    }

    public function test_settings_page_explains_missing_server_configuration(): void
    {
        config(['services.google.client_id' => null]);
        $this->app->forgetInstance(GoogleApi::class);
        $this->app->bind(GoogleApi::class, fn () => new GoogleApi(config('services.google')));

        $this->actingAs($this->user)->get('/settings/calendar')->assertSee('GOOGLE_CLIENT_ID');
        $this->actingAs($this->user)->post('/settings/calendar/google')->assertServiceUnavailable();
    }

    public function test_connect_redirects_to_google_with_offline_access_and_minimal_scopes(): void
    {
        $response = $this->actingAs($this->user)->post('/settings/calendar/google');

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(session('google_oauth_state'), $query['state']);
        $this->assertSame(
            'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
            $query['scope'],
        );
    }

    public function test_callback_stores_an_encrypted_connection(): void
    {
        $this->queueGoogle(
            $this->googleJson(['access_token' => 'ya29.fresh', 'refresh_token' => '1//refresh', 'expires_in' => 3599, 'scope' => self::GRANTED, 'token_type' => 'Bearer']),
            $this->googleJson(['email' => 'jane@gmail.com', 'verified_email' => true]),
        );

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'expected-state'])
            ->get('/settings/calendar/google/callback?state=expected-state&code=auth-code')
            ->assertRedirect('/settings/calendar')
            ->assertSessionHas('status', 'calendar-connected');

        $connection = $this->user->googleConnection()->sole();
        $this->assertSame('jane@gmail.com', $connection->account_email);
        $this->assertSame('ya29.fresh', $connection->access_token);
        $this->assertSame('1//refresh', $connection->refresh_token);
        $this->assertSame(ConnectionStatus::Active, $connection->status);

        // Tokens are encrypted at rest.
        $raw = DB::table('calendar_connections')->first();
        $this->assertStringNotContainsString('1//refresh', $raw->refresh_token);
        $this->assertStringNotContainsString('ya29.fresh', $raw->access_token);

        $tokenRequest = $this->googleRequests()[0];
        parse_str((string) $tokenRequest->getBody(), $form);
        $this->assertSame('auth-code', $form['code']);
        $this->assertSame('authorization_code', $form['grant_type']);
    }

    public function test_callback_rejects_a_wrong_state(): void
    {
        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'expected-state'])
            ->get('/settings/calendar/google/callback?state=forged&code=auth-code')
            ->assertRedirect('/settings/calendar')
            ->assertSessionHas('error');

        $this->assertNull($this->user->googleConnection()->first());
        $this->assertSame([], $this->googleRequests());
    }

    public function test_callback_handles_the_user_declining(): void
    {
        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 's'])
            ->get('/settings/calendar/google/callback?state=s&error=access_denied')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'access_denied'));

        $this->assertNull($this->user->googleConnection()->first());
    }

    public function test_callback_requires_both_calendar_permissions(): void
    {
        $this->queueGoogle($this->googleJson([
            'access_token' => 'ya29.fresh', 'refresh_token' => '1//refresh', 'expires_in' => 3599,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.freebusy',
        ]));

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 's'])
            ->get('/settings/calendar/google/callback?state=s&code=c')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'calendar permissions'));

        $this->assertNull($this->user->googleConnection()->first());
    }

    public function test_reconnecting_replaces_a_revoked_connection(): void
    {
        $this->connectGoogle($this->user, ['status' => ConnectionStatus::Revoked, 'last_error' => 'invalid_grant']);
        $this->queueGoogle(
            $this->googleJson(['access_token' => 'ya29.new', 'refresh_token' => '1//new', 'expires_in' => 3599, 'scope' => self::GRANTED]),
            $this->googleJson(['email' => 'host@gmail.com']),
        );

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 's'])
            ->get('/settings/calendar/google/callback?state=s&code=c');

        $connection = $this->user->googleConnection()->sole();
        $this->assertSame(ConnectionStatus::Active, $connection->status);
        $this->assertNull($connection->last_error);
        $this->assertSame('1//new', $connection->refresh_token);
    }

    public function test_disconnect_revokes_at_google_and_removes_the_connection(): void
    {
        $this->connectGoogle($this->user);
        $this->queueGoogle($this->googleJson([]));

        $this->actingAs($this->user)->delete('/settings/calendar/google')->assertRedirect('/settings/calendar');

        $this->assertNull($this->user->googleConnection()->first());
        $this->assertStringContainsString('oauth2.googleapis.com/revoke', (string) $this->googleRequests()[0]->getUri());
    }
}
