<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'provider', 'account_email', 'access_token', 'refresh_token', 'token_expires_at',
    'scopes', 'write_calendar_id', 'busy_calendar_ids', 'status', 'last_error', 'last_synced_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class CalendarConnection extends Model
{
    public const GOOGLE = 'google';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'busy_calendar_ids' => 'array',
            'status' => ConnectionStatus::class,
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }

    /**
     * Calendars whose events count as busy (the write calendar by default).
     *
     * @return list<string>
     */
    public function busyCalendars(): array
    {
        return $this->busy_calendar_ids ?: [$this->write_calendar_id];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
