<?php

namespace Tests\Feature;

use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_displayed(): void
    {
        $user = User::factory()->create();
        $eventType = EventType::factory()->for($user)->create(['title' => 'Demo call']);

        $this->actingAs($user)->get('/event-types')->assertOk()->assertSee('Demo call');
        $this->actingAs($user)->get('/event-types/create')->assertOk();
        $this->actingAs($user)->get("/event-types/{$eventType->id}/edit")->assertOk();
    }

    public function test_an_event_type_can_be_created_with_questions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/event-types', $this->validForm([
                'questions' => [
                    ['label' => 'Company size', 'type' => 'select', 'options' => "1-10\r\n11-50\n\n11-50\n 51+ ", 'is_required' => '1'],
                    ['label' => 'Website', 'type' => 'text', 'options' => 'ignored', 'is_required' => '0'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $eventType = $user->eventTypes()->sole();
        $this->assertSame('demo-call', $eventType->slug);
        $this->assertSame(LocationType::GoogleMeet, $eventType->location_type);
        $this->assertNull($eventType->location_value);
        $this->assertSame([1440, 60], $eventType->reminder_offsets);
        $this->assertSame(1440, $eventType->min_notice_minutes);
        $this->assertTrue($eventType->is_active);

        [$size, $website] = $eventType->questions->all();
        $this->assertSame(QuestionType::Select, $size->type);
        $this->assertSame(['1-10', '11-50', '51+'], $size->options);
        $this->assertTrue($size->is_required);
        $this->assertSame(0, $size->position);
        $this->assertNull($website->options);
        $this->assertSame(1, $website->position);
    }

    public function test_questions_are_synced_on_update(): void
    {
        $user = User::factory()->create();
        $eventType = EventType::factory()->for($user)->create();
        $keep = $eventType->questions()->create(['label' => 'Keep me', 'type' => 'text', 'position' => 0]);
        $drop = $eventType->questions()->create(['label' => 'Drop me', 'type' => 'text', 'position' => 1]);

        $other = EventType::factory()->create();
        $foreign = $other->questions()->create(['label' => 'Not yours', 'type' => 'text', 'position' => 0]);

        $this->actingAs($user)
            ->put("/event-types/{$eventType->id}", $this->validForm([
                'slug' => $eventType->slug,
                'questions' => [
                    ['label' => 'New one', 'type' => 'textarea'],
                    ['id' => $keep->id, 'label' => 'Kept and renamed', 'type' => 'phone'],
                    // An id from another event type must not hijack that question.
                    ['id' => $foreign->id, 'label' => 'Sneaky', 'type' => 'text'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $labels = $eventType->questions()->pluck('label', 'position')->all();
        $this->assertSame([0 => 'New one', 1 => 'Kept and renamed', 2 => 'Sneaky'], $labels);
        $this->assertSame('Kept and renamed', $keep->fresh()->label);
        $this->assertModelMissing($drop);
        $this->assertSame('Not yours', $foreign->fresh()->label);
    }

    public function test_slug_is_unique_per_user_only(): void
    {
        $user = User::factory()->create();
        EventType::factory()->for($user)->create(['slug' => 'demo-call']);
        EventType::factory()->create(['slug' => 'other']);

        $this->actingAs($user)->post('/event-types', $this->validForm())->assertSessionHasErrors('slug');
        $this->actingAs($user)->post('/event-types', $this->validForm(['slug' => 'other']))->assertSessionHasNoErrors();
    }

    public function test_deleting_frees_the_slug(): void
    {
        $user = User::factory()->create();
        $eventType = EventType::factory()->for($user)->create(['slug' => 'demo-call']);

        $this->actingAs($user)->delete("/event-types/{$eventType->id}")->assertRedirect('/event-types');

        $this->assertSoftDeleted($eventType);
        $this->assertSame("demo-call--deleted-{$eventType->id}", EventType::withTrashed()->find($eventType->id)->slug);
        $this->actingAs($user)->post('/event-types', $this->validForm())->assertSessionHasNoErrors();
    }

    public function test_custom_location_requires_details_and_others_drop_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/event-types', $this->validForm(['location_type' => 'custom', 'location_value' => '']))
            ->assertSessionHasErrors('location_value');

        $this->actingAs($user)
            ->post('/event-types', $this->validForm(['location_type' => 'phone', 'location_value' => 'leftover']))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->eventTypes()->sole()->location_value);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/event-types', $this->validForm([
            'slug' => 'Bad Slug!',
            'duration_minutes' => 2,
            'buffer_before_minutes' => -5,
            'max_days_ahead' => 0,
            'daily_limit' => 0,
            'location_type' => 'zoom',
            'reminder_offsets' => [1440, 7],
            'questions' => [['label' => 'Pick one', 'type' => 'select', 'options' => '']],
        ]))->assertSessionHasErrors([
            'slug', 'duration_minutes', 'buffer_before_minutes', 'max_days_ahead', 'daily_limit',
            'location_type', 'reminder_offsets.1', 'questions.0.options',
        ]);

        $this->assertSame(0, $user->eventTypes()->count());
    }

    public function test_members_cannot_access_each_others_event_types(): void
    {
        $eventType = EventType::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/event-types/{$eventType->id}/edit")->assertNotFound();
        $this->actingAs($intruder)->put("/event-types/{$eventType->id}", $this->validForm())->assertNotFound();
        $this->actingAs($intruder)->delete("/event-types/{$eventType->id}")->assertNotFound();

        $this->assertNotSoftDeleted($eventType);
    }

    public function test_user_input_is_escaped_on_the_index_page(): void
    {
        $user = User::factory()->create();
        EventType::factory()->for($user)->create(['title' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get('/event-types')
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('&lt;script&gt;', escape: false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Demo call',
            'slug' => 'demo-call',
            'description' => 'A quick product walkthrough.',
            'duration_minutes' => 30,
            'slot_interval_minutes' => null,
            'location_type' => 'google_meet',
            'location_value' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 10,
            'min_notice_minutes' => 1440,
            'max_days_ahead' => 60,
            'daily_limit' => null,
            'reminder_offsets' => [60, 1440],
            'is_active' => '1',
        ], $overrides);
    }
}
