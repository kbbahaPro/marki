@php
    $weekdayNames = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];

    // After a failed submit, rebuild the form from the old input instead of the database.
    if (old('timezone') !== null) {
        $oldDays = old('days', []);
        $days = array_map(fn ($day) => [
            'weekday' => $day['weekday'],
            'intervals' => array_values($oldDays[$day['weekday']]['intervals'] ?? []),
        ], $days);
    }

    $formDays = array_map(fn ($day) => $day + [
        'label' => $weekdayNames[$day['weekday']],
        'enabled' => count($day['intervals']) > 0,
    ], $days);

    $oldOverrideIntervals = array_values(old('intervals', [['start' => '09:00', 'end' => '17:00']]));
@endphp

<x-app-layout :title="__('Availability')">
    <x-slot name="header">
        <h1 class="page-title">
            {{ __('Availability') }}
        </h1>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 space-y-6">
            {{-- Weekly hours --}}
            <div class="card p-5 sm:p-8">
                <section class="max-w-2xl">
                    <header>
                        <h2 class="section-title">{{ __('Weekly hours') }}</h2>
                        <p class="mt-1 text-sm text-ink-2">{{ __('When you are generally available for meetings. Times are in the time zone below, and stay correct across daylight saving changes.') }}</p>
                    </header>

                    <form method="post" action="{{ route('availability.update') }}" class="mt-6 space-y-6"
                          x-data="{
                              days: @js($formDays),
                              toggle(day) { if (day.enabled && day.intervals.length === 0) day.intervals.push({ start: '09:00', end: '17:00' }) },
                              add(day) {
                                  const last = day.intervals[day.intervals.length - 1];
                                  day.intervals.push(last ? { start: last.end, end: last.end } : { start: '09:00', end: '17:00' });
                              },
                              remove(day, i) { day.intervals.splice(i, 1); if (day.intervals.length === 0) day.enabled = false },
                          }">
                        @csrf
                        @method('put')

                        <div>
                            <x-input-label for="timezone" :value="__('Time zone')" />
                            <x-timezone-select id="timezone" name="timezone" class="mt-1 block w-full" :selected="old('timezone', $schedule->timezone)" required />
                            <x-input-error class="mt-2" :messages="$errors->get('timezone')" />
                        </div>

                        <x-input-error :messages="$errors->get('days')" />

                        <div class="divide-y divide-line">
                            <template x-for="day in days" :key="day.weekday">
                                <div class="py-3 flex flex-col sm:flex-row sm:items-start gap-3">
                                    <label class="inline-flex items-center w-36 shrink-0 pt-2">
                                        <input type="checkbox" class="checkbox" x-model="day.enabled" @change="toggle(day)">
                                        <span class="ms-2 text-sm font-medium text-ink" x-text="day.label"></span>
                                    </label>

                                    <div class="flex-1 space-y-2">
                                        <p class="pt-2 text-sm text-ink-3" x-show="! day.enabled">{{ __('Unavailable') }}</p>

                                        <template x-for="(interval, i) in day.intervals" :key="i">
                                            <div class="flex items-center gap-2" x-show="day.enabled">
                                                <input type="time" step="300" required x-model="interval.start" :disabled="! day.enabled"
                                                       :name="`days[${day.weekday}][intervals][${i}][start]`"
                                                       class="input text-sm">
                                                <span class="text-ink-3">–</span>
                                                <input type="time" step="300" required x-model="interval.end" :disabled="! day.enabled"
                                                       :name="`days[${day.weekday}][intervals][${i}][end]`"
                                                       class="input text-sm">
                                                <button type="button" @click="remove(day, i)" class="btn-ghost min-h-0 h-9 w-9 p-0 hover:text-danger" aria-label="{{ __('Remove') }}"><x-icon name="x" class="h-4 w-4" /></button>
                                            </div>
                                        </template>

                                        <button type="button" x-show="day.enabled" @click="add(day)" class="btn-ghost -ms-3 min-h-0 py-1.5 text-primary-ink"><x-icon name="plus" class="h-4 w-4" />{{ __('Add hours') }}</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <x-input-error :messages="collect($errors->getMessages())
                            ->filter(fn ($messages, $key) => str_starts_with($key, 'days.'))
                            ->map(fn ($messages, $key) => ($weekdayNames[(int) explode('.', $key)[1]] ?? '').': '.$messages[0])
                            ->unique()->values()->all()" />

                        <p class="text-xs text-ink-3">{{ __('An end time of 00:00 means midnight at the end of the day.') }}</p>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            @if (session('status') === 'availability-updated')
                                <p class="text-sm text-ink-2">{{ __('Saved.') }}</p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>

            {{-- Date overrides --}}
            <div class="card p-5 sm:p-8">
                <section class="max-w-2xl">
                    <header>
                        <h2 class="section-title">{{ __('Date overrides') }}</h2>
                        <p class="mt-1 text-sm text-ink-2">{{ __('Days off or different hours on specific dates. These replace your weekly hours for that date.') }}</p>
                    </header>

                    @if ($overrides->isNotEmpty())
                        <ul class="mt-4 divide-y divide-line">
                            @foreach ($overrides as $date => $rows)
                                <li class="py-3 flex items-center justify-between gap-4">
                                    <div class="text-sm">
                                        <div class="font-medium text-ink">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l, F j, Y') }}</div>
                                        <div class="text-ink-3">
                                            @if ($rows->first()->isUnavailable())
                                                {{ __('Unavailable') }}
                                            @else
                                                {{ $rows->map(fn ($r) => \App\Support\TimeOfDay::format($r->start_minute).'–'.\App\Support\TimeOfDay::format($r->end_minute))->implode(', ') }}
                                            @endif
                                        </div>
                                    </div>
                                    <form method="post" action="{{ route('availability.overrides.destroy', $date) }}">
                                        @csrf
                                        @method('delete')
                                        <x-secondary-button type="submit">{{ __('Remove') }}</x-secondary-button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <form method="post" action="{{ route('availability.overrides.store') }}" class="mt-6 space-y-4"
                          x-data="{ unavailable: @js((bool) old('unavailable', true)), intervals: @js($oldOverrideIntervals) }">
                        @csrf

                        <div>
                            <x-input-label for="override_date" :value="__('Date')" />
                            <x-text-input id="override_date" name="date" type="date" class="mt-1 block" :min="$today" :value="old('date')" required />
                            <x-input-error class="mt-2" :messages="$errors->override->get('date')" />
                        </div>

                        <label class="inline-flex items-center">
                            <input type="hidden" name="unavailable" value="0">
                            <input type="checkbox" name="unavailable" value="1" x-model="unavailable" class="checkbox">
                            <span class="ms-2 text-sm text-ink-2">{{ __('Unavailable all day') }}</span>
                        </label>

                        <div class="space-y-2" x-show="! unavailable">
                            <template x-for="(interval, i) in intervals" :key="i">
                                <div class="flex items-center gap-2">
                                    <input type="time" step="300" required x-model="interval.start" :disabled="unavailable" :name="`intervals[${i}][start]`"
                                           class="input text-sm">
                                    <span class="text-ink-3">–</span>
                                    <input type="time" step="300" required x-model="interval.end" :disabled="unavailable" :name="`intervals[${i}][end]`"
                                           class="input text-sm">
                                    <button type="button" x-show="intervals.length > 1" @click="intervals.splice(i, 1)" class="btn-ghost min-h-0 h-9 w-9 p-0 hover:text-danger" aria-label="{{ __('Remove') }}"><x-icon name="x" class="h-4 w-4" /></button>
                                </div>
                            </template>
                            <button type="button" @click="intervals.push({ start: '09:00', end: '17:00' })" class="btn-ghost -ms-3 min-h-0 py-1.5 text-primary-ink"><x-icon name="plus" class="h-4 w-4" />{{ __('Add hours') }}</button>
                            <x-input-error :messages="$errors->override->get('intervals')" />
                        </div>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Save override') }}</x-primary-button>
                            @if (session('status') === 'override-saved')
                                <p class="text-sm text-ink-2">{{ __('Override saved.') }}</p>
                            @elseif (session('status') === 'override-deleted')
                                <p class="text-sm text-ink-2">{{ __('Override removed.') }}</p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
