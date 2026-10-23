<?php

namespace App\Http\Requests;

use App\Support\TimeOfDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScheduleOverrideRequest extends FormRequest
{
    /**
     * Keep these errors apart from the weekly hours form on the same page.
     *
     * @var string
     */
    protected $errorBag = 'override';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = now($this->user()->defaultSchedule->timezone)->toDateString();
        $unavailable = fn () => $this->boolean('unavailable');

        return [
            'date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}"],
            'unavailable' => ['sometimes', 'boolean'],
            'intervals' => [Rule::excludeIf($unavailable), 'required', 'array', 'min:1', 'max:12'],
            'intervals.*.start' => [Rule::excludeIf($unavailable), 'required', 'date_format:H:i'],
            'intervals.*.end' => [Rule::excludeIf($unavailable), 'required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->boolean('unavailable')) {
                    return;
                }

                if ($error = TimeOfDay::validateIntervals(TimeOfDay::parseIntervals($this->input('intervals')))) {
                    $validator->errors()->add('intervals', $error);
                }
            },
        ];
    }

    /**
     * Rows to store for the date; a single null row means "unavailable".
     *
     * @return list<array{date: string, start_minute: ?int, end_minute: ?int}>
     */
    public function overrideRows(): array
    {
        $date = $this->validated('date');

        if ($this->boolean('unavailable')) {
            return [['date' => $date, 'start_minute' => null, 'end_minute' => null]];
        }

        return array_map(
            fn (array $i) => ['date' => $date, 'start_minute' => $i[0], 'end_minute' => $i[1]],
            TimeOfDay::parseIntervals($this->validated('intervals')),
        );
    }
}
