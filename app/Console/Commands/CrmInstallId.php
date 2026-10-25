<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints a new install id to paste into .env as MARKI_INSTALL_ID. It never
 * edits .env: the id must stay the same once the CRM knows it.
 */
class CrmInstallId extends Command
{
    protected $signature = 'crm:install-id';

    protected $description = 'Generate an install id for the CRM integration (MARKI_INSTALL_ID)';

    public function handle(): int
    {
        if (filled(config('services.crm.install_id'))) {
            $this->components->warn('MARKI_INSTALL_ID is already set. Changing it disconnects this install from the CRM.');
        }

        $this->line('MARKI_INSTALL_ID='.Str::ulid());

        return self::SUCCESS;
    }
}
