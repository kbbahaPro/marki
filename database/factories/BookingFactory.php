<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'host_id' => fn (array $attributes) => EventType::find($attributes['event_type_id'])->user_id,
            'start_at' => CarbonImmutable::parse('2026-11-09 10:00:00'),
            'end_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['start_at'])->addMinutes(30),
            'blocked_start_at' => fn (array $attributes) => $attributes['start_at'],
            'blocked_end_at' => fn (array $attributes) => $attributes['end_at'],
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'guest_timezone' => 'UTC',
            'status' => BookingStatus::Confirmed,
            'manage_token_hash' => fn () => hash('sha256', Str::random(64)),
            'location_type' => fn (array $attributes) => EventType::find($attributes['event_type_id'])->location_type,
        ];
    }

    /**
     * Book a given event type at a UTC start time, deriving end and buffer zone.
     */
    public function at(EventType $eventType, string $start): static
    {
        $start = CarbonImmutable::parse($start)->utc();
        $end = $start->addMinutes($eventType->duration_minutes);

        return $this->state([
            'event_type_id' => $eventType->id,
            'host_id' => $eventType->user_id,
            'start_at' => $start,
            'end_at' => $end,
            'blocked_start_at' => $start->subMinutes($eventType->buffer_before_minutes),
            'blocked_end_at' => $end->addMinutes($eventType->buffer_after_minutes),
            'location_type' => $eventType->location_type,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => BookingStatus::Cancelled]);
    }
}
