<?php

namespace App\Models;

use App\Support\TimeOfDay;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'is_default'])]
class Schedule extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Give a new team member a default schedule from config('booking.default_working_hours').
     */
    public static function createDefaultFor(User $user): self
    {
        $hours = config('booking.default_working_hours');

        $schedule = $user->schedules()->create([
            'name' => __('Working hours'),
            'timezone' => $user->timezone,
            'is_default' => true,
        ]);

        $schedule->rules()->createMany(array_map(fn (int $weekday) => [
            'weekday' => $weekday,
            'start_minute' => TimeOfDay::toMinutes($hours['start']),
            'end_minute' => TimeOfDay::toMinutes($hours['end'], isEnd: true),
        ], $hours['weekdays']));

        return $schedule;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class)->orderBy('weekday')->orderBy('start_minute');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ScheduleOverride::class)->orderBy('date')->orderBy('start_minute');
    }
}
