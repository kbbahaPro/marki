<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A possible host of a team event type.
 */
class EventTypeHost extends Pivot
{
    protected $table = 'event_type_hosts';

    public $incrementing = true;

    public $timestamps = false;

    /** Labels for the five round-robin priority levels (0–4). */
    public const PRIORITIES = [0 => 'Lowest', 1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Highest'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_fixed' => 'boolean',
            'priority' => 'integer',
            'weight' => 'integer',
        ];
    }
}
