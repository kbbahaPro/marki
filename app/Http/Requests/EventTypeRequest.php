<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Models\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EventTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var EventType|null $eventType */
        $eventType = $this->route('eventType');

        return [
            'title' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('event_types')->where('user_id', $this->user()->id)->ignore($eventType?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'location_type' => ['required', Rule::enum(LocationType::class)],
            'location_value' => [
                Rule::requiredIf(fn () => $this->input('location_type') === LocationType::Custom->value),
                'nullable', 'string', 'max:500',
            ],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:86400'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'reminder_offsets' => ['nullable', 'array', 'max:3'],
            'reminder_offsets.*' => ['integer', 'distinct', Rule::in(config('booking.reminder_options'))],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['nullable', 'array', 'max:10'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.options' => ['nullable', 'string', 'max:2000'],
            'questions.*.is_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => __('URL slug'),
            'location_value' => __('location details'),
            'questions.*.label' => __('question'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->input('questions', []) as $i => $question) {
                    if (($question['type'] ?? null) === QuestionType::Select->value && $this->splitOptions($question['options'] ?? '') === []) {
                        $validator->errors()->add("questions.{$i}.options", __('Dropdown questions need at least one option.'));
                    }
                }
            },
        ];
    }

    /**
     * Attributes for the event type itself.
     *
     * @return array<string, mixed>
     */
    public function eventTypeAttributes(): array
    {
        $data = collect($this->validated())->except('questions')->all();

        $data['is_active'] = $this->boolean('is_active');
        $data['reminder_offsets'] = collect($data['reminder_offsets'] ?? [])
            ->map(fn ($offset) => (int) $offset)->sortDesc()->values()->all();

        if ($data['location_type'] !== LocationType::Custom->value) {
            $data['location_value'] = null;
        }

        return $data;
    }

    /**
     * Normalised custom questions, in submitted order.
     *
     * @return list<array{id: ?int, label: string, type: string, options: ?array<int, string>, is_required: bool, position: int}>
     */
    public function questions(): array
    {
        // validated() rebuilds nested arrays rule by rule, so restore the submitted order first.
        return collect($this->validated('questions') ?? [])->sortKeys()->values()->map(fn (array $q, int $position) => [
            'id' => isset($q['id']) ? (int) $q['id'] : null,
            'label' => $q['label'],
            'type' => $q['type'],
            'options' => $q['type'] === QuestionType::Select->value ? $this->splitOptions($q['options'] ?? '') : null,
            'is_required' => (bool) ($q['is_required'] ?? false),
            'position' => $position,
        ])->all();
    }

    /**
     * @return list<string>
     */
    private function splitOptions(?string $options): array
    {
        return collect(preg_split('/\R/', (string) $options))
            ->map(fn ($option) => trim($option))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
