@php
    use App\Enums\LocationType;
    use App\Enums\QuestionType;

    $locationIcon = match ($eventType->location_type) {
        LocationType::GoogleMeet => 'video',
        LocationType::Phone => 'phone',
        LocationType::Custom => 'map-pin',
    };
@endphp

<x-public-layout :title="$eventType->title">
    <div class="card grid overflow-hidden shadow-xl shadow-ink/[0.04] md:grid-cols-[19rem_1fr]" x-data="bookingPage(@js($config))">
        {{-- Event summary --}}
        <aside class="border-b border-line p-6 sm:p-8 md:border-b-0 md:border-r">
            <a href="{{ route('public.profile', $host->username) }}" class="group inline-flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary text-base font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($host->name, 0, 1)) }}</span>
                <span class="text-sm font-medium text-ink-2 group-hover:text-ink">{{ $host->name }}</span>
            </a>

            <h1 class="mt-5 text-2xl font-bold tracking-tight text-ink">{{ $eventType->title }}</h1>

            <ul class="mt-5 space-y-3 text-sm text-ink-2">
                <li class="flex items-center gap-3"><x-icon name="clock" class="h-4 w-4 text-ink-3" />{{ trans_choice(':count minute|:count minutes', $eventType->duration_minutes) }}</li>
                <li class="flex items-center gap-3"><x-icon :name="$locationIcon" class="h-4 w-4 text-ink-3" />{{ $eventType->location_type === LocationType::Custom ? $eventType->location_value : $eventType->location_type->label() }}</li>
                <li class="flex items-center gap-3"><x-icon name="globe" class="h-4 w-4 text-ink-3" /><span x-text="timezone ? timezone.replaceAll('_', ' ') : ''"></span></li>
            </ul>

            <div x-show="selected" x-cloak class="mt-6 rounded-2xl bg-primary-soft p-4 text-primary-ink">
                <div class="text-xs font-semibold uppercase tracking-wider opacity-80">{{ __('Your time') }}</div>
                <div class="mt-1 flex items-start gap-2 font-semibold"><x-icon name="calendar-check" class="mt-0.5 h-4 w-4" /><span x-text="selected ? formatLong(selected) : ''"></span></div>
            </div>

            @if ($reschedule)
                <div class="mt-6 alert-warning flex-col gap-1">
                    <div class="font-semibold">{{ __('Rescheduling') }}</div>
                    <div>{{ __('Currently') }}: {{ $reschedule->start_at->setTimezone($reschedule->guest_timezone)->translatedFormat('D, M j, H:i') }} ({{ str_replace('_', ' ', $reschedule->guest_timezone) }})</div>
                </div>
            @endif

            @if ($eventType->description)
                <div class="mt-6 whitespace-pre-line border-t border-line pt-6 text-sm leading-relaxed text-ink-2">{{ $eventType->description }}</div>
            @endif
        </aside>

        <section class="p-6 sm:p-8">
            @error('start')
                <div class="mb-6 alert-danger" role="alert"><x-icon name="alert" class="mt-0.5 h-4 w-4" /><p>{{ $message }}</p></div>
            @enderror

            {{-- Step 1: pick a time --}}
            <div x-show="! selected">
                <h2 class="text-lg font-semibold text-ink">{{ __('Select a date & time') }}</h2>

                <div class="mt-6 grid gap-8 lg:grid-cols-[1fr_12rem]">
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-ink" x-text="monthLabel()"></span>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="changeMonth(-1)" :disabled="! canGoBack()" class="btn-ghost min-h-0 h-10 w-10 p-0 disabled:opacity-30" aria-label="{{ __('Previous month') }}"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                                <button type="button" @click="changeMonth(1)" :disabled="! canGoForward()" class="btn-ghost min-h-0 h-10 w-10 p-0 disabled:opacity-30" aria-label="{{ __('Next month') }}"><x-icon name="chevron-right" class="h-4 w-4" /></button>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-7 text-center text-xs font-semibold uppercase tracking-wide text-ink-3">
                            <template x-for="name in weekdayNames()" :key="name"><div class="py-2" x-text="name"></div></template>
                        </div>
                        <div class="grid grid-cols-7 gap-y-1 text-center transition-opacity" :class="loading && 'opacity-40'" :aria-busy="loading">
                            <template x-for="cell in calendarCells()" :key="cell.key">
                                <div class="flex justify-center">
                                    <button type="button" x-show="cell.date" @click="selectDate(cell.date)" :disabled="! days[cell.date]"
                                            class="relative flex h-11 w-11 items-center justify-center rounded-full text-sm tabular-nums transition-colors"
                                            :class="cell.date === selectedDate
                                                ? 'bg-primary font-bold text-white'
                                                : (days[cell.date] ? 'bg-primary-soft font-semibold text-primary-ink hover:bg-primary hover:text-white' : 'cursor-default text-ink-3/60')"
                                            :aria-pressed="cell.date === selectedDate"
                                            x-text="cell.day"></button>
                                </div>
                            </template>
                        </div>

                        <p x-show="loading" class="mt-4 flex items-center gap-2 text-sm text-ink-3" role="status">
                            <span class="h-2 w-6 animate-pulse rounded-full bg-primary/40" aria-hidden="true"></span>{{ __('Loading available times…') }}
                        </p>
                        <p x-show="! loading && ! failed && Object.keys(days).length === 0" x-cloak class="mt-4 rounded-2xl bg-surface-2 px-4 py-3 text-sm text-ink-2">{{ __('No available times this month. Try the next one.') }}</p>
                        <p x-show="failed" x-cloak class="mt-4 alert-danger" role="alert">{{ __('Could not load available times. Please try again.') }}</p>

                        <label class="mt-6 block">
                            <span class="label">{{ __('Time zone') }}</span>
                            <select x-model="timezone" @change="load()" class="input mt-1.5 text-sm">
                                <template x-for="tz in config.timezones" :key="tz"><option :value="tz" x-text="tz.replaceAll('_', ' ')" :selected="tz === timezone"></option></template>
                            </select>
                        </label>
                    </div>

                    <div x-show="selectedDate" x-cloak>
                        <h3 class="text-sm font-semibold text-ink" x-text="selectedDate ? formatDay(selectedDate) : ''"></h3>
                        <div class="mt-4 max-h-[26rem] space-y-2 overflow-y-auto pe-1">
                            <template x-for="slot in (days[selectedDate] || [])" :key="slot">
                                <button type="button" @click="selected = slot" class="slot tabular-nums" x-text="formatTime(slot)"></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 2: details --}}
            <form method="post" action="{{ $formAction }}" x-show="selected" x-cloak class="space-y-5">
                @csrf
                <input type="hidden" name="start" :value="selected">
                <input type="hidden" name="timezone" :value="timezone">

                <div>
                    <button type="button" @click="selected = null" class="btn-ghost -ms-3 min-h-0 py-1.5 text-sm"><x-icon name="arrow-left" class="h-4 w-4" />{{ __('Change time') }}</button>
                    <h2 class="mt-2 text-lg font-semibold text-ink">{{ $reschedule ? __('Confirm the new time') : __('Your details') }}</h2>
                </div>

                @if ($reschedule)
                    <p class="text-sm text-ink-2">
                        {{ __('Your booking will move to') }}
                        <strong class="text-ink" x-text="selected ? formatLong(selected) : ''"></strong>.
                        {{ __('Your details and answers stay the same.') }}
                    </p>

                    <x-primary-button>{{ __('Reschedule booking') }}</x-primary-button>
                @else
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input id="name" name="name" type="text" class="mt-1.5" :value="old('name')" required maxlength="255" autocomplete="name" />
                            <x-input-error class="mt-2" :messages="$errors->get('name')" />
                        </div>

                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1.5" :value="old('email')" required maxlength="255" autocomplete="email" />
                            <x-input-error class="mt-2" :messages="$errors->get('email')" />
                        </div>
                    </div>

                    @if ($eventType->location_type === LocationType::Phone)
                        <div>
                            <x-input-label for="guest_phone" :value="__('Phone number (we will call you)')" />
                            <x-text-input id="guest_phone" name="guest_phone" type="tel" class="mt-1.5" :value="old('guest_phone')" required maxlength="40" autocomplete="tel" />
                            <x-input-error class="mt-2" :messages="$errors->get('guest_phone')" />
                        </div>
                    @endif

                    @foreach ($eventType->questions as $question)
                        @php($field = "answers[{$question->id}]")
                        @php($old = old("answers.{$question->id}"))
                        <div>
                            @if ($question->type === QuestionType::Checkbox)
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="hidden" name="{{ $field }}" value="0">
                                    <input type="checkbox" name="{{ $field }}" value="1" @checked($old) @required($question->is_required) class="checkbox mt-0.5">
                                    <span class="text-sm text-ink">{{ $question->label }}</span>
                                </label>
                            @else
                                <x-input-label :for="'q'.$question->id" :value="$question->label.($question->is_required ? '' : ' '.__('(optional)'))" />
                                @switch($question->type)
                                    @case(QuestionType::Textarea)
                                        <textarea id="q{{ $question->id }}" name="{{ $field }}" rows="3" maxlength="5000" @required($question->is_required) class="input mt-1.5">{{ $old }}</textarea>
                                        @break
                                    @case(QuestionType::Select)
                                        <select id="q{{ $question->id }}" name="{{ $field }}" @required($question->is_required) class="input mt-1.5">
                                            <option value="">{{ __('Choose…') }}</option>
                                            @foreach ($question->options ?? [] as $option)
                                                <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @default
                                        <x-text-input :id="'q'.$question->id" :name="$field" :type="$question->type === QuestionType::Phone ? 'tel' : 'text'" class="mt-1.5" :value="$old" maxlength="1000" :required="$question->is_required" />
                                @endswitch
                            @endif
                            <x-input-error class="mt-2" :messages="$errors->get('answers.'.$question->id)" />
                        </div>
                    @endforeach

                    <div>
                        <x-input-label for="notes" :value="__('Anything to share before the meeting? (optional)')" />
                        <textarea id="notes" name="notes" rows="3" maxlength="2000" class="input mt-1.5">{{ old('notes') }}</textarea>
                        <x-input-error class="mt-2" :messages="$errors->get('notes')" />
                    </div>

                    {{-- Honeypot: invisible to people, tempting to bots. --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="flex flex-wrap items-center gap-4 pt-2">
                        <x-primary-button>{{ __('Confirm booking') }}</x-primary-button>
                        <p class="text-xs text-ink-3">{{ __('You will get a confirmation email with a calendar invitation.') }}</p>
                    </div>
                @endif
            </form>
        </section>
    </div>
</x-public-layout>
