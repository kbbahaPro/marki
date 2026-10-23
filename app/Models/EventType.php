<?php

namespace App\Models;

use App\Enums\LocationType;
use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title', 'slug', 'description', 'duration_minutes', 'slot_interval_minutes',
    'location_type', 'location_value', 'buffer_before_minutes', 'buffer_after_minutes',
    'min_notice_minutes', 'max_days_ahead', 'daily_limit', 'reminder_offsets', 'is_active',
])]
class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_type' => LocationType::class,
            'duration_minutes' => 'integer',
            'slot_interval_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_days_ahead' => 'integer',
            'daily_limit' => 'integer',
            'reminder_offsets' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Free the slug so the host can reuse it; "--" never appears in a valid slug.
        static::deleting(function (EventType $eventType) {
            if (! $eventType->isForceDeleting()) {
                $eventType->slug = "{$eventType->slug}--deleted-{$eventType->id}";
                $eventType->saveQuietly();
            }
        });
    }

    public function publicUrl(): string
    {
        return url("{$this->user->username}/{$this->slug}");
    }

    /**
     * The schedule used to compute availability for this event type.
     */
    public function effectiveSchedule(): ?Schedule
    {
        return $this->schedule ?? $this->user->defaultSchedule;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(EventTypeQuestion::class)->orderBy('position');
    }
}
