<?php

namespace App\Jobs;

use App\Services\Crm\CrmConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Delivers one booking event to the CRM (contract v1, WEBHOOKS.md).
 *
 * The payload is a snapshot taken when the event happened, so retries send
 * the same event id and the CRM can drop repeats. Each attempt signs with a
 * fresh timestamp, so a late retry is never rejected as stale.
 */
class SendCrmWebhook implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 10;

    /** The first attempt plus five retries. Overrides the worker's --tries. */
    public int $tries = 6;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * Seconds to wait before each retry: 1 min, 5 min, 30 min, 2 h, 6 h.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(): void
    {
        if (! CrmConfig::webhooksEnabled()) {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(self::headers($this->payload, $timestamp, $body))
                ->withBody($body, 'application/json')
                ->post(config('services.crm.webhook_url'));
        } catch (ConnectionException $e) {
            throw new RuntimeException("CRM webhook {$this->payload['id']} could not connect: {$e->getMessage()}", previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        $code = $response->json('error.code');

        // Retrying cannot help: the request itself or the configuration is wrong.
        if ($response->status() === 401 || ($response->status() === 400 && $code !== 'stale_timestamp')) {
            Log::error('CRM rejected a booking webhook; check MARKI_INSTALL_ID and CRM_WEBHOOK_SECRET.', $this->context($response));
            $this->fail(new RuntimeException("CRM webhook {$this->payload['id']} rejected with HTTP {$response->status()} ({$code})."));

            return;
        }

        // 429, 5xx, stale timestamp and anything unexpected: try again later.
        Log::warning('CRM webhook delivery failed; it will be retried.', $this->context($response));

        throw new RuntimeException("CRM webhook {$this->payload['id']} got HTTP {$response->status()}.");
    }

    /**
     * The signed request headers for $body, sent at $timestamp.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function headers(array $payload, string $timestamp, string $body): array
    {
        return [
            'Accept' => 'application/json',
            'X-Marki-Event' => $payload['type'],
            'X-Marki-Delivery' => $payload['id'],
            'X-Marki-Timestamp' => $timestamp,
            'X-Marki-Signature' => 'v1='.hash_hmac('sha256', "{$timestamp}.{$body}", (string) config('services.crm.webhook_secret')),
        ];
    }

    public function failed(?Throwable $e): void
    {
        Log::error('CRM webhook gave up.', [
            'event_id' => $this->payload['id'],
            'type' => $this->payload['type'],
            'booking' => $this->payload['booking']['id'] ?? null,
            'error' => $e?->getMessage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Response $response): array
    {
        return [
            'event_id' => $this->payload['id'],
            'type' => $this->payload['type'],
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ];
    }
}
