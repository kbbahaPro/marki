<?php

namespace App\Http\Requests;

use App\Support\TimeOfDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Weekly hours arrive as days[weekday][intervals][i][start|end]; days without
 * intervals are unavailable (the form disables their inputs).
 */
class UpdateAvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'timezone:all'],
            'days' => ['nullable', 'array'],
            'days.*.intervals' => ['required', 'array', 'min:1', 'max:12'],
            'days.*.intervals.*.start' => ['required', 'date_format:H:i'],
            'days.*.intervals.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->input('days', []) as $weekday => $day) {
                    if (! ctype_digit((string) $weekday) || (int) $weekday > 6) {
                        $validator->errors()->add('days', __('Invalid day of the week.'));

                        return;
                    }

                    if ($error = TimeOfDay::validateIntervals(TimeOfDay::parseIntervals($day['intervals']))) {
                        $validator->errors()->add("days.{$weekday}", $error);
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{weekday: int, start_minute: int, end_minute: int}>
     */
    public function weeklyRules(): array
    {
        $rules = [];

        foreach ($this->validated('days') ?? [] as $weekday => $day) {
            foreach (TimeOfDay::parseIntervals($day['intervals']) as [$start, $end]) {
                $rules[] = ['weekday' => (int) $weekday, 'start_minute' => $start, 'end_minute' => $end];
            }
        }

        return $rules;
    }
}
