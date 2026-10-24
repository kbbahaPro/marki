<?php

namespace Tests\Support;

use App\Enums\ConnectionStatus;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Calendar\Google\GoogleApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Runs the real Google client library against queued fake HTTP responses
 * and records every request it makes.
 */
trait FakesGoogle
{
    protected MockHandler $googleResponses;

    /** @var list<array{request: RequestInterface}> */
    protected array $googleHistory = [];

    protected function fakeGoogle(Response ...$responses): void
    {
        $this->googleResponses = new MockHandler($responses);
        $this->googleHistory = [];

        // Record at the innermost layer so requests are seen exactly as sent,
        // after Google's auth middleware has added the Authorization header.
        $stack = HandlerStack::create(function ($request, array $options) {
            $this->googleHistory[] = ['request' => $request];

            return ($this->googleResponses)($request, $options);
        });

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/settings/calendar/google/callback',
        ]);

        // Google's own client is built without HTTP exceptions, so mirror that.
        $this->app->instance(GoogleApi::class, new GoogleApi(
            config('services.google'),
            new Client(['handler' => $stack, 'http_errors' => false]),
        ));
    }

    protected function queueGoogle(Response ...$responses): void
    {
        foreach ($responses as $response) {
            $this->googleResponses->append($response);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function googleJson(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    protected function googleError(int $status, string $reason = 'backendError'): Response
    {
        return $this->googleJson(['error' => [
            'code' => $status,
            'message' => $reason,
            'errors' => [['domain' => 'global', 'reason' => $reason, 'message' => $reason]],
        ]], $status);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $busy  [startIso, endIso] pairs.
     */
    protected function freeBusyResponse(array $busy = [], string $calendar = 'primary'): Response
    {
        return $this->googleJson(['kind' => 'calendar#freeBusy', 'calendars' => [
            $calendar => ['busy' => array_map(fn ($p) => ['start' => $p[0], 'end' => $p[1]], $busy)],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function connectGoogle(User $user, array $overrides = []): CalendarConnection
    {
        return $user->googleConnection()->create(array_merge([
            'provider' => CalendarConnection::GOOGLE,
            'account_email' => 'host@gmail.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => implode(' ', GoogleApi::SCOPES),
            'status' => ConnectionStatus::Active,
        ], $overrides));
    }

    /**
     * @return list<RequestInterface>
     */
    protected function googleRequests(): array
    {
        return array_map(fn ($entry) => $entry['request'], $this->googleHistory);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestJson(RequestInterface $request): array
    {
        return json_decode((string) $request->getBody(), true);
    }
}
