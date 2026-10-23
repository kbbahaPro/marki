<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_get_a_default_schedule_in_their_time_zone(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Berlin']);

        $schedule = $user->defaultSchedule;
        $this->assertSame('Europe/Berlin', $schedule->timezone);
        $this->assertSame([1, 2, 3, 4, 5], $schedule->rules->pluck('weekday')->all());
        $this->assertSame(540, $schedule->rules->first()->start_minute);
        $this->assertSame(1020, $schedule->rules->first()->end_minute);
    }

    public function test_availability_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/availability')->assertOk()->assertSee('Weekly hours');
    }

    public function test_weekly_hours_can_be_replaced(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/availability', [
                'timezone' => 'Asia/Tokyo',
                'days' => [
                    1 => ['intervals' => [['start' => '13:00', 'end' => '17:00'], ['start' => '08:00', 'end' => '12:00']]],
                    6 => ['intervals' => [['start' => '22:00', 'end' => '00:00']]],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/availability');

        $schedule = $user->defaultSchedule()->first();
        $this->assertSame('Asia/Tokyo', $schedule->timezone);
        $this->assertSame(
            [[1, 480, 720], [1, 780, 1020], [6, 1320, 1440]],
            $schedule->rules->map(fn ($r) => [$r->weekday, $r->start_minute, $r->end_minute])->all(),
        );
    }

    public function test_all_days_can_be_made_unavailable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/availability', ['timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->defaultSchedule->rules()->count());
    }

    public function test_overlapping_or_inverted_hours_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/availability', [
                'timezone' => 'UTC',
                'days' => [2 => ['intervals' => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '14:00']]]],
            ])
            ->assertSessionHasErrors('days.2');

        $this->actingAs($user)
            ->put('/availability', [
                'timezone' => 'UTC',
                'days' => [3 => ['intervals' => [['start' => '17:00', 'end' => '09:00']]]],
            ])
            ->assertSessionHasErrors('days.3');

        // The default rules are untouched.
        $this->assertSame(5, $user->defaultSchedule->rules()->count());
    }

    public function test_invalid_weekday_time_or_time_zone_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/availability', ['timezone' => 'UTC', 'days' => [9 => ['intervals' => [['start' => '09:00', 'end' => '10:00']]]]])
            ->assertSessionHasErrors('days');

        $this->actingAs($user)
            ->put('/availability', ['timezone' => 'UTC', 'days' => [1 => ['intervals' => [['start' => '9am', 'end' => '10:00']]]]])
            ->assertSessionHasErrors('days.1.intervals.0.start');

        $this->actingAs($user)
            ->put('/availability', ['timezone' => 'Not/AZone'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_a_day_off_override_can_be_added_and_removed(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/availability/overrides', ['date' => '2026-11-10', 'unavailable' => '1'])
            ->assertSessionHasNoErrors();

        $override = $user->defaultSchedule->overrides()->sole();
        $this->assertSame('2026-11-10', $override->date->toDateString());
        $this->assertTrue($override->isUnavailable());

        $this->actingAs($user)->delete('/availability/overrides/2026-11-10')->assertRedirect('/availability');
        $this->assertSame(0, $user->defaultSchedule->overrides()->count());
    }

    public function test_custom_hours_override_replaces_the_existing_one_for_that_date(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/availability/overrides', ['date' => '2026-11-10', 'unavailable' => '1']);
        $this->actingAs($user)
            ->post('/availability/overrides', [
                'date' => '2026-11-10',
                'unavailable' => '0',
                'intervals' => [['start' => '14:00', 'end' => '16:00'], ['start' => '10:00', 'end' => '12:00']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [[600, 720], [840, 960]],
            $user->defaultSchedule->overrides()->get()->map(fn ($o) => [$o->start_minute, $o->end_minute])->all(),
        );
    }

    public function test_override_validation_uses_its_own_error_bag(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/availability/overrides', ['date' => '2026-11-01', 'unavailable' => '1'])
            ->assertSessionHasErrorsIn('override', 'date');

        $this->actingAs($user)
            ->post('/availability/overrides', ['date' => '2026-11-10', 'unavailable' => '0', 'intervals' => [['start' => '12:00', 'end' => '10:00']]])
            ->assertSessionHasErrorsIn('override', 'intervals');
    }

    public function test_overrides_of_other_users_are_not_touched(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $owner = User::factory()->create();
        $owner->defaultSchedule->overrides()->create(['date' => '2026-11-10']);

        $this->actingAs(User::factory()->create())->delete('/availability/overrides/2026-11-10');

        $this->assertSame(1, $owner->defaultSchedule->overrides()->count());
    }
}
