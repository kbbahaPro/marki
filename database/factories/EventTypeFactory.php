<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => '30 minute meeting',
            'slug' => fake()->unique()->regexify('[a-z]{8}'),
            'description' => null,
            'duration_minutes' => 30,
            'slot_interval_minutes' => null,
            'location_type' => LocationType::GoogleMeet,
            'location_value' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'min_notice_minutes' => 0,
            'max_days_ahead' => 60,
            'daily_limit' => null,
            'reminder_offsets' => [1440, 60],
            'is_active' => true,
        ];
    }
}
