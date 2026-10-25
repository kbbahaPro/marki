<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the CRM whether a guest is an existing contact (GET /api/v1/contacts/lookup),
 * for the badge on a host's booking page. Results are cached; any failure
 * just means no badge.
 */
class CrmLookup
{
    /** How long an unreachable CRM is left alone before asking again. */
    private const FAILURE_CACHE_SECONDS = 60;

    /**
     * The CRM contact for $email, or null when there is none, the lookup is
     * not configured, or the CRM could not be reached.
     *
     * @return array{name: ?string, owner_name: ?string, companies: list<string>, open_deals_count: int, crm_url: ?string}|null
     */
    public function contact(string $email): ?array
    {
        if (! CrmConfig::lookupEnabled()) {
            return null;
        }

        $email = mb_strtolower(trim($email));
        $key = 'crm-lookup:'.hash('sha256', $email);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached['contact'];
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.crm.api_url'), '/'))
                ->withToken((string) config('services.crm.api_key'))
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(3)
                ->get('/api/v1/contacts/lookup', ['email' => $email]);

            if (! $response->successful()) {
                Log::warning('CRM lookup failed.', ['status' => $response->status()]);
                Cache::put($key, ['contact' => null], self::FAILURE_CACHE_SECONDS);

                return null;
            }

            $contact = $response->json('found') === true ? $this->normalize((array) $response->json('contact')) : null;
        } catch (Throwable $e) {
            Log::warning('CRM lookup failed.', ['error' => $e->getMessage()]);
            Cache::put($key, ['contact' => null], self::FAILURE_CACHE_SECONDS);

            return null;
        }

        Cache::put($key, ['contact' => $contact], (int) config('services.crm.lookup_cache_seconds'));

        return $contact;
    }

    /**
     * Keep only what the badge shows, with types it can trust.
     *
     * @param  array<string, mixed>  $contact
     * @return array{name: ?string, owner_name: ?string, companies: list<string>, open_deals_count: int, crm_url: ?string}
     */
    private function normalize(array $contact): array
    {
        $url = $contact['crm_url'] ?? null;

        return [
            'name' => is_string($contact['name'] ?? null) ? $contact['name'] : null,
            'owner_name' => is_string($contact['owner_name'] ?? null) ? $contact['owner_name'] : null,
            'companies' => array_values(array_filter((array) ($contact['companies'] ?? []), 'is_string')),
            'open_deals_count' => (int) ($contact['open_deals_count'] ?? 0),
            // Rendered as a link, so only http(s).
            'crm_url' => is_string($url) && preg_match('#^https?://#i', $url) ? $url : null,
        ];
    }
}
