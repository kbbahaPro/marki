@php
    use App\Enums\SchedulingType;
    use App\Models\EventTypeHost;
@endphp

{{-- Scheduling type and host picker for team event types. Expects $team, $schedulingType and $hostRows. --}}
<div class="card p-5 sm:p-8" x-data="{ schedulingType: @js($schedulingType) }">
    <div class="max-w-3xl space-y-6">
        <fieldset>
            <legend class="section-title">{{ __('Who attends') }}</legend>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach (SchedulingType::teamCases() as $type)
                    <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition-colors"
                           :class="schedulingType === @js($type->value) ? 'border-primary bg-primary-soft' : 'border-line hover:border-ink-3'">
                        <input type="radio" name="scheduling_type" value="{{ $type->value }}" x-model="schedulingType" class="mt-0.5 text-primary focus:ring-primary">
                        <span>
                            <span class="block font-semibold text-ink">{{ $type->label() }}</span>
                            <span class="mt-0.5 block text-sm text-ink-2">{{ $type->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('scheduling_type')" />
        </fieldset>

        <fieldset>
            <legend class="block font-medium text-sm text-ink">{{ __('Hosts') }}</legend>
            <p class="mt-1 text-sm text-ink-2" x-show="schedulingType === 'round_robin'">
                {{ __('Each booking goes to the available host with the highest priority; among equals, to whoever has had the fewest bookings in the last 30 days relative to their weight. Hosts who always attend join every booking.') }}
            </p>
            <p class="mt-1 text-sm text-ink-2" x-show="schedulingType === 'collective'" x-cloak>
                {{ __('Every host attends, so guests only see times when all of them are free.') }}
            </p>

            @if ($hostRows->isEmpty())
                <p class="mt-3 text-sm text-ink-2">{{ __('This team has no members yet.') }}</p>
            @else
                <ul class="mt-3 divide-y divide-line rounded-xl border border-line">
                    @foreach ($hostRows as $i => [$member, $selected, $fixed, $priority, $weight])
                        <li class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3" x-data="{ selected: @js($selected), fixed: @js($fixed) }">
                            <input type="hidden" name="hosts[{{ $i }}][user_id]" value="{{ $member->id }}" :disabled="! selected">
                            <label class="flex min-w-0 flex-1 basis-56 cursor-pointer items-center gap-3">
                                <input type="checkbox" x-model="selected" class="checkbox" aria-label="{{ __('Host: :name', ['name' => $member->name]) }}">
                                <x-avatar :user="$member" size="sm" decorative />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-ink">{{ $member->name }}</span>
                                    <span class="block truncate text-xs text-ink-3">{{ $member->headline() ?? $member->email }}</span>
                                </span>
                            </label>

                            {{-- Round robin settings stay posted for collective events; the server ignores them there. --}}
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2" x-show="schedulingType === 'round_robin'" :class="! selected && 'opacity-50'">
                                <label class="inline-flex items-center">
                                    <input type="hidden" name="hosts[{{ $i }}][is_fixed]" :value="fixed ? 1 : 0" :disabled="! selected">
                                    <input type="checkbox" x-model="fixed" :disabled="! selected" class="checkbox">
                                    <span class="ms-2 text-sm text-ink-2">{{ __('Always attends') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-ink-2" x-show="! fixed">
                                    {{ __('Priority') }}
                                    <select name="hosts[{{ $i }}][priority]" :disabled="! selected" class="input min-h-0 py-1.5 text-sm">
                                        @foreach (EventTypeHost::PRIORITIES as $value => $label)
                                            <option value="{{ $value }}" @selected($value === $priority)>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-ink-2" x-show="! fixed">
                                    {{ __('Weight') }}
                                    <input type="number" name="hosts[{{ $i }}][weight]" value="{{ $weight }}" min="1" max="1000" required :disabled="! selected"
                                           class="input min-h-0 w-20 py-1.5 text-sm">
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-input-error class="mt-2" :messages="collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'hosts'))->flatten()->unique()->values()->all()" />
        </fieldset>
    </div>
</div>
