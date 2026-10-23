<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Models\EventType;
use App\Models\EventTypeQuestion;
use App\Services\Booking\GuestDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    private const PHONE_PATTERN = '/^[0-9+().\-\s]{6,40}$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventType = $this->eventType();

        $rules = [
            'start' => ['required', 'string', 'date'],
            'timezone' => ['required', 'timezone:all'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'guest_phone' => [
                Rule::requiredIf($eventType->location_type === LocationType::Phone),
                'nullable', 'string', 'regex:'.self::PHONE_PATTERN,
            ],
            'answers' => ['nullable', 'array'],
            // Honeypot: hidden from people, filled in by naive bots.
            'website' => ['nullable', 'max:0'],
        ];

        foreach ($eventType->questions as $question) {
            $rules["answers.{$question->id}"] = $this->rulesFor($question);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->eventType()->questions
            ->mapWithKeys(fn (EventTypeQuestion $q) => ["answers.{$q->id}" => $q->label])
            ->put('guest_phone', __('phone number'))
            ->all();
    }

    /**
     * The event type named in the URL; inactive or deleted ones are 404s.
     */
    public function eventType(): EventType
    {
        return once(fn () => EventType::query()
            ->whereHas('user', fn ($q) => $q->where('username', $this->route('username')))
            ->where('slug', $this->route('slug'))
            ->where('is_active', true)
            ->with(['user', 'questions'])
            ->firstOrFail());
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('start'))->utc();
    }

    public function guest(): GuestDetails
    {
        $answers = $this->eventType()->questions->values()->map(fn (EventTypeQuestion $q, int $position) => [
            'question_id' => $q->id,
            'label' => $q->label,
            'value' => $this->answerValue($q),
            'position' => $position,
        ])->all();

        return new GuestDetails(
            name: $this->validated('name'),
            email: $this->validated('email'),
            timezone: $this->validated('timezone'),
            notes: $this->validated('notes'),
            phone: $this->validated('guest_phone'),
            answers: $answers,
        );
    }

    /**
     * @return list<mixed>
     */
    private function rulesFor(EventTypeQuestion $question): array
    {
        $presence = $question->is_required
            ? ($question->type === QuestionType::Checkbox ? 'accepted' : 'required')
            : 'nullable';

        return [$presence, ...match ($question->type) {
            QuestionType::Text => ['string', 'max:1000'],
            QuestionType::Textarea => ['string', 'max:5000'],
            QuestionType::Select => ['string', Rule::in($question->options ?? [])],
            QuestionType::Checkbox => ['boolean'],
            QuestionType::Phone => ['string', 'regex:'.self::PHONE_PATTERN],
        }];
    }

    private function answerValue(EventTypeQuestion $question): ?string
    {
        $value = $this->validated("answers.{$question->id}");

        if ($question->type === QuestionType::Checkbox) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? __('Yes') : __('No');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
