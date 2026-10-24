<?php

namespace App\Services\Calendar\Google;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Oauth2;
use GuzzleHttp\ClientInterface;

/**
 * Builds configured Google API clients. Tests pass a Guzzle client backed by
 * a mock handler so no real HTTP request leaves the test process.
 */
class GoogleApi
{
    /** Least privilege: write events, read free/busy, and identify the account. */
    public const SCOPES = [
        'openid',
        'email',
        Calendar::CALENDAR_EVENTS,
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];

    /**
     * @param  array{client_id: ?string, client_secret: ?string, redirect: ?string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?ClientInterface $http = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->config['client_id']) && filled($this->config['client_secret']);
    }

    public function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) $this->config['client_id']);
        $client->setClientSecret((string) $this->config['client_secret']);
        $client->setRedirectUri((string) $this->config['redirect']);
        $client->setScopes(self::SCOPES);
        // Offline + consent guarantees a refresh token on every connect.
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        if ($this->http) {
            $client->setHttpClient($this->http);
        }

        return $client;
    }

    public function authUrl(string $state): string
    {
        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array<string, mixed> Google's token response (contains "error" on failure).
     */
    public function exchangeCode(string $code): array
    {
        return $this->client()->fetchAccessTokenWithAuthCode($code);
    }

    /**
     * @return array<string, mixed> Google's token response (contains "error" on failure).
     */
    public function refresh(string $refreshToken): array
    {
        return $this->client()->fetchAccessTokenWithRefreshToken($refreshToken);
    }

    public function accountEmail(string $accessToken): ?string
    {
        $client = $this->client();
        $client->setAccessToken(['access_token' => $accessToken]);

        return (new Oauth2($client))->userinfo->get()->getEmail();
    }

    public function calendar(string $accessToken): Calendar
    {
        $client = $this->client();
        $client->setAccessToken(['access_token' => $accessToken]);

        return new Calendar($client);
    }

    public function revoke(string $token): bool
    {
        return $this->client()->revokeToken($token);
    }
}
