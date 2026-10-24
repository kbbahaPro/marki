<x-guest-layout>
    <h1 class="text-3xl font-bold tracking-tight text-ink">{{ __('Join the team') }}</h1>
    <p class="mb-8 mt-2 muted">{{ __('Set up your account for :email.', ['email' => $invitation->email]) }}</p>

    <form method="POST" action="{{ route('invitations.accept', $token) }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Username -->
        <div class="mt-4">
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autocomplete="off" />
            <p class="mt-1 text-xs text-ink-3">{{ __('Your booking links will look like :url', ['url' => url('/your-username/event')]) }}</p>
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <!-- Time zone -->
        {{-- Preselect the browser's zone, but only if PHP knows it (browsers may report aliases like Etc/GMT-1). --}}
        <div class="mt-4" x-data x-init="
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (! @js(old('timezone')) && [...$refs.tz.options].some(o => o.value === tz)) { $refs.tz.value = tz }
        ">
            <x-input-label for="timezone" :value="__('Time zone')" />
            <x-timezone-select id="timezone" name="timezone" x-ref="tz" class="block mt-1 w-full" :selected="old('timezone', config('booking.default_timezone'))" required />
            <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Create account') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
