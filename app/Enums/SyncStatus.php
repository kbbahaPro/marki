<?php

namespace App\Enums;

/**
 * Whether a booking's event exists in the host's external calendar.
 */
enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';

    /** The host has no calendar connected. */
    case Skipped = 'skipped';
}
