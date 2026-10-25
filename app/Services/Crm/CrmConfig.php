<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Log;

/**
 * Whether the optional CRM integration is configured. Everything is off
 * until the .env keys are filled in, so a plain install never calls out.
 */
class CrmConfig
{
    private static bool $warned = false;

    public static function webhooksEnabled(): bool
    {
        $values = [
            config('services.crm.install_id'),
            config('services.crm.webhook_url'),
            config('services.crm.webhook_secret'),
        ];
        $set = count(array_filter($values, 'filled'));

        if ($set > 0 && $set < count($values) && ! self::$warned) {
            self::$warned = true;
            Log::warning('CRM webhooks are off: set MARKI_INSTALL_ID, CRM_WEBHOOK_URL and CRM_WEBHOOK_SECRET together.');
        }

        return $set === count($values);
    }

    public static function lookupEnabled(): bool
    {
        return filled(config('services.crm.api_url')) && filled(config('services.crm.api_key'));
    }
}
