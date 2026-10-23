@php
    use App\Enums\LocationType;
    use App\Enums\QuestionType;
    use Carbon\CarbonInterval;

    // Re-render from old input after a failed submit (title is always posted).
    $hasOldInput = old('title') !== null;

    $questions = $hasOldInput
        ? array_map(fn ($q) => ['is_required' => (bool) ($q['is_required'] ?? false)] + $q, old('questions', []))
        : $eventType->questions->map(fn ($q) => [
            'id' => $q->id,
            'label' => $q->label,
            'type' => $q->type->value,
            'options' => implode("\n", $q->options ?? []),
            'is_required' => $q->is_required,
        ])->all();

    $reminders = array_map('intval', $hasOldInput ? old('reminder_offsets', []) : ($eventType->reminder_offsets ?? []));

    // Show minimum notice in the largest whole unit.
    $notice = (int) old('min_notice_minutes', $eventType->min_notice_minutes);
    $noticeUnit = $notice > 0 && $notice % 1440 === 0 ? 1440 : ($notice > 0 && $notice % 60 === 0 ? 60 : 1);
@endphp

@csrf

<div class="space-y-6"
     x-data="{
        title: @js(old('title', $eventType->title)),
        slug: @js(old('slug', $eventType->slug)),
        slugTouched: @js((bool) old('slug', $eventType->slug)),
        slugify(value) { return value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) },
        locationType: @js(old('location_type', $eventType->location_type?->value)),
        noticeAmount: @js($notice / $noticeUnit),
        noticeUnit: @js($noticeUnit),
        questions: @js(array_values($questions)),
        questionTypes: @js(collect(QuestionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
     }">

    {{-- Basics --}}
    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
        <div class="max-w-xl space-y-6">
            <div>
                <x-input-label for="title" :value="__('Name')" />
                <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" x-model="title"
                              @input="if (! slugTouched) slug = slugify(title)" required autofocus />
                <x-input-error class="mt-2" :messages="$errors->get('title')" />
            </div>

            <div>
                <x-input-label for="slug" :value="__('URL')" />
                <div class="mt-1 flex rounded-md shadow-sm">
                    <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">{{ url(auth()->user()->username) }}/</span>
                    <input id="slug" name="slug" type="text" x-model="slug" @input="slugTouched = true" required
                           class="block w-full min-w-0 flex-1 rounded-none rounded-r-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('slug')" />
            </div>

            <div>
                <x-input-label for="description" :value="__('Description (optional)')" />
                <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $eventType->description) }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('description')" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="duration_minutes" :value="__('Duration (minutes)')" />
                    <x-text-input id="duration_minutes" name="duration_minutes" type="number" min="5" max="720" step="5" list="duration-presets" class="mt-1 block w-full" :value="old('duration_minutes', $eventType->duration_minutes)" required />
                    <datalist id="duration-presets"><option value="15"><option value="30"><option value="45"><option value="60"><option value="90"></datalist>
                    <x-input-error class="mt-2" :messages="$errors->get('duration_minutes')" />
                </div>
                <div>
                    <x-input-label for="slot_interval_minutes" :value="__('Start times every (minutes)')" />
                    <x-text-input id="slot_interval_minutes" name="slot_interval_minutes" type="number" min="5" max="720" step="5" class="mt-1 block w-full" :value="old('slot_interval_minutes', $eventType->slot_interval_minutes)" :placeholder="__('Same as duration')" />
                    <x-input-error class="mt-2" :messages="$errors->get('slot_interval_minutes')" />
                </div>
            </div>

            <div>
                <x-input-label for="location_type" :value="__('Location')" />
                <select id="location_type" name="location_type" x-model="locationType" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @foreach (LocationType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500" x-show="locationType === 'google_meet'">{{ __('A Meet link is created when your Google Calendar is connected.') }}</p>
                <p class="mt-1 text-xs text-gray-500" x-show="locationType === 'phone'">{{ __('Guests will be asked for their phone number.') }}</p>
                <div x-show="locationType === 'custom'" class="mt-2">
                    <x-text-input id="location_value" name="location_value" type="text" class="block w-full" :value="old('location_value', $eventType->location_value)" :placeholder="__('Address, meeting link or dial-in details')" x-bind:disabled="locationType !== 'custom'" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('location_value')" />
            </div>

            <label class="inline-flex items-center">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $eventType->is_active)) class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <span class="ms-2 text-sm text-gray-600">{{ __('Accepting bookings') }}</span>
            </label>
        </div>
    </div>

    {{-- Limits --}}
    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <div class="max-w-xl space-y-6">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Scheduling rules') }}</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="buffer_before_minutes" :value="__('Buffer before (minutes)')" />
                    <x-text-input id="buffer_before_minutes" name="buffer_before_minutes" type="number" min="0" max="240" step="5" class="mt-1 block w-full" :value="old('buffer_before_minutes', $eventType->buffer_before_minutes)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('buffer_before_minutes')" />
                </div>
                <div>
                    <x-input-label for="buffer_after_minutes" :value="__('Buffer after (minutes)')" />
                    <x-text-input id="buffer_after_minutes" name="buffer_after_minutes" type="number" min="0" max="240" step="5" class="mt-1 block w-full" :value="old('buffer_after_minutes', $eventType->buffer_after_minutes)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('buffer_after_minutes')" />
                </div>
            </div>

            <div>
                <x-input-label for="notice_amount" :value="__('Minimum notice')" />
                <div class="mt-1 flex gap-2">
                    <x-text-input id="notice_amount" type="number" min="0" class="block w-32" x-model.number="noticeAmount" />
                    <select x-model.number="noticeUnit" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="1">{{ __('minutes') }}</option>
                        <option value="60">{{ __('hours') }}</option>
                        <option value="1440">{{ __('days') }}</option>
                    </select>
                </div>
                <input type="hidden" name="min_notice_minutes" :value="Math.round((noticeAmount || 0) * noticeUnit)">
                <x-input-error class="mt-2" :messages="$errors->get('min_notice_minutes')" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="max_days_ahead" :value="__('Bookable up to (days ahead)')" />
                    <x-text-input id="max_days_ahead" name="max_days_ahead" type="number" min="1" max="365" class="mt-1 block w-full" :value="old('max_days_ahead', $eventType->max_days_ahead)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('max_days_ahead')" />
                </div>
                <div>
                    <x-input-label for="daily_limit" :value="__('Max bookings per day')" />
                    <x-text-input id="daily_limit" name="daily_limit" type="number" min="1" max="100" class="mt-1 block w-full" :value="old('daily_limit', $eventType->daily_limit)" :placeholder="__('No limit')" />
                    <x-input-error class="mt-2" :messages="$errors->get('daily_limit')" />
                </div>
            </div>

            <fieldset>
                <legend class="block font-medium text-sm text-gray-700">{{ __('Email reminders to the guest (up to 3)') }}</legend>
                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (config('booking.reminder_options') as $offset)
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="reminder_offsets[]" value="{{ $offset }}" @checked(in_array($offset, $reminders, true)) class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <span class="ms-2 text-sm text-gray-600">{{ __(':time before', ['time' => CarbonInterval::minutes($offset)->cascade()->forHumans()]) }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('reminder_offsets')" />
            </fieldset>
        </div>
    </div>

    {{-- Custom questions --}}
    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <div class="max-w-xl space-y-4">
            <div>
                <h3 class="text-lg font-medium text-gray-900">{{ __('Booking questions') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('Name, email and notes are always asked. Add anything else you need to know.') }}</p>
            </div>

            <template x-for="(question, i) in questions" :key="i">
                <div class="rounded-md border border-gray-200 p-4 space-y-3">
                    <input type="hidden" :name="`questions[${i}][id]`" :value="question.id ?? ''">
                    <div class="flex gap-2">
                        <input type="text" :name="`questions[${i}][label]`" x-model="question.label" required placeholder="{{ __('Question') }}"
                               class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <select :name="`questions[${i}][type]`" x-model="question.type" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <template x-for="(label, value) in questionTypes" :key="value">
                                <option :value="value" x-text="label" :selected="value === question.type"></option>
                            </template>
                        </select>
                    </div>
                    <textarea x-show="question.type === 'select'" :name="`questions[${i}][options]`" x-model="question.options" rows="3"
                              placeholder="{{ __('One option per line') }}"
                              class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"></textarea>
                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center">
                            <input type="hidden" :name="`questions[${i}][is_required]`" value="0">
                            <input type="checkbox" :name="`questions[${i}][is_required]`" value="1" x-model="question.is_required" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <span class="ms-2 text-sm text-gray-600">{{ __('Required') }}</span>
                        </label>
                        <button type="button" @click="questions.splice(i, 1)" class="text-sm text-gray-500 hover:text-red-600">{{ __('Remove') }}</button>
                    </div>
                </div>
            </template>

            <x-input-error :messages="collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'questions'))->flatten()->unique()->values()->all()" />

            <button type="button" x-show="questions.length < 10" @click="questions.push({ id: null, label: '', type: 'text', options: '', is_required: false })"
                    class="text-sm text-indigo-600 hover:text-indigo-800">+ {{ __('Add question') }}</button>
        </div>
    </div>
</div>
