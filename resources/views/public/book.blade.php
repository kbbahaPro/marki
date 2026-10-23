@php
    use App\Enums\LocationType;
    use App\Enums\QuestionType;
@endphp

<x-public-layout :title="$eventType->title">
    <div class="bg-white shadow sm:rounded-lg grid md:grid-cols-[18rem_1fr]" x-data="bookingPage(@js($config))">
        {{-- Event summary --}}
        <aside class="p-6 border-b md:border-b-0 md:border-r border-gray-100">
            <a href="{{ route('public.profile', $host->username) }}" class="text-sm text-gray-500 hover:text-gray-700">{{ $host->name }}</a>
            <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ $eventType->title }}</h1>
            <ul class="mt-4 space-y-2 text-sm text-gray-600">
                <li>⏱ {{ trans_choice(':count minute|:count minutes', $eventType->duration_minutes) }}</li>
                <li>📍 {{ $eventType->location_type === LocationType::Custom ? $eventType->location_value : $eventType->location_type->label() }}</li>
                <li x-show="selected" x-cloak class="font-medium text-gray-900">🗓 <span x-text="selected ? formatLong(selected) : ''"></span></li>
            </ul>
            @if ($eventType->description)
                <div class="mt-4 text-sm text-gray-600 whitespace-pre-line">{{ $eventType->description }}</div>
            @endif
        </aside>

        <section class="p-6">
            @error('start')
                <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">{{ $message }}</div>
            @enderror

            {{-- Step 1: pick a time --}}
            <div x-show="! selected">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-medium text-gray-900 whitespace-nowrap">{{ __('Select a date & time') }}</h2>
                    <div class="flex items-center gap-1">
                        <button type="button" @click="changeMonth(-1)" :disabled="! canGoBack()" class="rounded p-2 text-gray-600 hover:bg-gray-100 disabled:opacity-30" aria-label="{{ __('Previous month') }}">‹</button>
                        <span class="w-36 text-center text-sm font-medium" x-text="monthLabel()"></span>
                        <button type="button" @click="changeMonth(1)" :disabled="! canGoForward()" class="rounded p-2 text-gray-600 hover:bg-gray-100 disabled:opacity-30" aria-label="{{ __('Next month') }}">›</button>
                    </div>
                </div>

                <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_11rem]">
                    <div>
                        <div class="grid grid-cols-7 text-center text-xs font-medium text-gray-500">
                            <template x-for="name in weekdayNames()" :key="name"><div class="py-1" x-text="name"></div></template>
                        </div>
                        <div class="grid grid-cols-7 gap-1 text-center" :class="loading && 'opacity-50'">
                            <template x-for="cell in calendarCells()" :key="cell.key">
                                <div>
                                    <button type="button" x-show="cell.date" @click="selectDate(cell.date)" :disabled="! days[cell.date]"
                                            class="mx-auto flex h-10 w-10 items-center justify-center rounded-full text-sm"
                                            :class="cell.date === selectedDate ? 'bg-indigo-600 text-white' : (days[cell.date] ? 'bg-indigo-50 text-indigo-700 font-semibold hover:bg-indigo-100' : 'text-gray-300 cursor-default')"
                                            x-text="cell.day"></button>
                                </div>
                            </template>
                        </div>
                        <p x-show="! loading && Object.keys(days).length === 0" x-cloak class="mt-4 text-sm text-gray-500">{{ __('No available times this month.') }}</p>
                        <p x-show="failed" x-cloak class="mt-4 text-sm text-red-600">{{ __('Could not load available times. Please try again.') }}</p>

                        <label class="mt-6 block text-sm text-gray-600">
                            <span class="font-medium">{{ __('Time zone') }}</span>
                            <select x-model="timezone" @change="load()" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <template x-for="tz in config.timezones" :key="tz"><option :value="tz" x-text="tz.replaceAll('_', ' ')" :selected="tz === timezone"></option></template>
                            </select>
                        </label>
                    </div>

                    <div x-show="selectedDate" x-cloak>
                        <h3 class="text-sm font-medium text-gray-900" x-text="selectedDate ? formatDay(selectedDate) : ''"></h3>
                        <div class="mt-3 max-h-96 space-y-2 overflow-y-auto pr-1">
                            <template x-for="slot in (days[selectedDate] || [])" :key="slot">
                                <button type="button" @click="selected = slot" class="block w-full rounded-md border border-indigo-200 py-2 text-sm font-semibold text-indigo-700 hover:border-indigo-600" x-text="formatTime(slot)"></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 2: details --}}
            <form method="post" action="{{ route('public.book.store', [$host->username, $eventType->slug]) }}" x-show="selected" x-cloak class="space-y-4">
                @csrf
                <input type="hidden" name="start" :value="selected">
                <input type="hidden" name="timezone" :value="timezone">

                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('Enter details') }}</h2>
                    <button type="button" @click="selected = null" class="text-sm text-indigo-600 hover:text-indigo-800">← {{ __('Change time') }}</button>
                </div>

                <div>
                    <x-input-label for="name" :value="__('Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required maxlength="255" autocomplete="name" />
                    <x-input-error class="mt-2" :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required maxlength="255" autocomplete="email" />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />
                </div>

                @if ($eventType->location_type === LocationType::Phone)
                    <div>
                        <x-input-label for="guest_phone" :value="__('Phone number (we will call you)')" />
                        <x-text-input id="guest_phone" name="guest_phone" type="tel" class="mt-1 block w-full" :value="old('guest_phone')" required maxlength="40" autocomplete="tel" />
                        <x-input-error class="mt-2" :messages="$errors->get('guest_phone')" />
                    </div>
                @endif

                @foreach ($eventType->questions as $question)
                    @php($field = "answers[{$question->id}]")
                    @php($old = old("answers.{$question->id}"))
                    <div>
                        @if ($question->type === QuestionType::Checkbox)
                            <label class="inline-flex items-start gap-2">
                                <input type="hidden" name="{{ $field }}" value="0">
                                <input type="checkbox" name="{{ $field }}" value="1" @checked($old) @required($question->is_required) class="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                <span class="text-sm text-gray-700">{{ $question->label }}</span>
                            </label>
                        @else
                            <x-input-label :for="'q'.$question->id" :value="$question->label.($question->is_required ? '' : ' '.__('(optional)'))" />
                            @switch($question->type)
                                @case(QuestionType::Textarea)
                                    <textarea id="q{{ $question->id }}" name="{{ $field }}" rows="3" maxlength="5000" @required($question->is_required) class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ $old }}</textarea>
                                    @break
                                @case(QuestionType::Select)
                                    <select id="q{{ $question->id }}" name="{{ $field }}" @required($question->is_required) class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">{{ __('Choose…') }}</option>
                                        @foreach ($question->options ?? [] as $option)
                                            <option value="{{ $option }}" @selected($old === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @default
                                    <x-text-input :id="'q'.$question->id" :name="$field" :type="$question->type === QuestionType::Phone ? 'tel' : 'text'" class="mt-1 block w-full" :value="$old" maxlength="1000" :required="$question->is_required" />
                            @endswitch
                        @endif
                        <x-input-error class="mt-2" :messages="$errors->get('answers.'.$question->id)" />
                    </div>
                @endforeach

                <div>
                    <x-input-label for="notes" :value="__('Anything to share before the meeting? (optional)')" />
                    <textarea id="notes" name="notes" rows="3" maxlength="2000" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('notes')" />
                </div>

                {{-- Honeypot: invisible to people, tempting to bots. --}}
                <div class="hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
                </div>

                <x-primary-button>{{ __('Confirm booking') }}</x-primary-button>
            </form>
        </section>
    </div>
</x-public-layout>
