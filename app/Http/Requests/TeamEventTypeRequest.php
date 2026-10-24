<?php

namespace App\Http\Requests;

use App\Enums\SchedulingType;
use App\Models\EventType;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A team event type: the personal fields plus a scheduling type and hosts.
 */
class TeamEventTypeRequest extends EventTypeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');
        /** @var EventType|null $eventType */
        $eventType = $this->route('eventType');

        return [
            ...parent::rules(),
            'slug' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('event_types')->where('team_id', $team->id)->ignore($eventType?->id),
            ],
            'scheduling_type' => ['required', Rule::in(array_map(fn ($type) => $type->value, SchedulingType::teamCases()))],
            'hosts' => ['required', 'array'],
            'hosts.*.user_id' => ['required', 'integer', 'distinct', Rule::in($team->members()->pluck('users.id')->all())],
            'hosts.*.is_fixed' => ['sometimes', 'boolean'],
            'hosts.*.priority' => ['required', 'integer', 'min:0', 'max:4'],
            'hosts.*.weight' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'hosts.*.user_id' => __('host'),
            'hosts.*.priority' => __('priority'),
            'hosts.*.weight' => __('weight'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hosts.required' => __('Choose at least one host.'),
            'hosts.*.user_id.in' => __('Hosts must be members of the team.'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['scheduling_type', 'hosts'])) {
                    return;
                }

                $hosts = collect($this->input('hosts', []));

                if ($this->input('scheduling_type') === SchedulingType::Collective->value && $hosts->count() < 2) {
                    $validator->errors()->add('hosts', __('A collective event needs at least two hosts.'));
                }

                if ($this->input('scheduling_type') === SchedulingType::RoundRobin->value
                    && $hosts->doesntContain(fn ($host) => ! filter_var($host['is_fixed'] ?? false, FILTER_VALIDATE_BOOLEAN))) {
                    $validator->errors()->add('hosts', __('Round robin needs at least one host who takes turns (not “always attends”).'));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function eventTypeAttributes(): array
    {
        return collect(parent::eventTypeAttributes())->except('hosts')->all();
    }

    /**
     * Pivot data for hosts()->sync(), keyed by user id.
     *
     * @return array<int, array{is_fixed: bool, priority: int, weight: int}>
     */
    public function hosts(): array
    {
        $collective = $this->validated('scheduling_type') === SchedulingType::Collective->value;

        return collect($this->validated('hosts'))->mapWithKeys(fn (array $host) => [
            (int) $host['user_id'] => [
                // Everyone attends a collective event; priority and weight only matter for round robin.
                'is_fixed' => $collective || (bool) ($host['is_fixed'] ?? false),
                'priority' => $collective ? 2 : (int) $host['priority'],
                'weight' => $collective ? 100 : (int) $host['weight'],
            ],
        ])->all();
    }
}
