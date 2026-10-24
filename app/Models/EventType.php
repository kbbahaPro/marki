<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\SchedulingType;
use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Owned by one person (user_id, scheduling type "single") or by a team
 * (team_id, "round_robin" or "collective", hosts in event_type_hosts).
 */
#[Fillable([
    'title', 'slug', 'description', 'duration_minutes', 'slot_interval_minutes',
    'location_type', 'location_value', 'buffer_before_minutes', 'buffer_after_minutes',
    'min_notice_minutes', 'max_days_ahead', 'daily_limit', 'reminder_offsets', 'is_active',
    'scheduling_type',
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
            'scheduling_type' => SchedulingType::class,
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

    public function isTeamEvent(): bool
    {
        return $this->team_id !== null;
    }

    public function publicUrl(): string
    {
        return $this->isTeamEvent()
            ? url("team/{$this->team->slug}/{$this->slug}")
            : url("{$this->user->username}/{$this->slug}");
    }

    /**
     * The schedule used to compute availability for a personal event type.
     * Team event types use each host's own schedule.
     */
    public function effectiveSchedule(): ?Schedule
    {
        return $this->schedule ?? $this->user?->defaultSchedule;
    }

    /**
     * Update questions in place by id, create new ones and delete removed ones.
     * Existing booking answers keep a copy of the label, so deleting is safe.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    public function syncQuestions(array $questions): void
    {
        $keep = [];

        foreach ($questions as $data) {
            $attributes = collect($data)->except('id')->all();
            // Scoped lookup: an id from another event type is treated as a new question.
            $question = $data['id'] ? $this->questions()->find($data['id']) : null;

            if ($question) {
                $question->update($attributes);
            } else {
                $question = $this->questions()->create($attributes);
            }

            $keep[] = $question->id;
        }

        $this->questions()->whereNotIn('id', $keep)->delete();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Possible hosts of a team event type, with their round-robin settings.
     */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_type_hosts')
            ->using(EventTypeHost::class)
            ->withPivot(['is_fixed', 'priority', 'weight'])
            ->orderBy('name');
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
