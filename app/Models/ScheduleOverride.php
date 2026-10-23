<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Replaces the weekly rules for one local date. A row with null minutes
 * marks the whole day unavailable.
 */
#[Fillable(['date', 'start_minute', 'end_minute'])]
class ScheduleOverride extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
        ];
    }

    public function isUnavailable(): bool
    {
        return $this->start_minute === null;
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
