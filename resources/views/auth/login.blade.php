<x-guest-layout>
    <h1 class="text-3xl font-bold tracking-tight text-ink">{{ __('Welcome back') }}</h1>
    <p class="mt-2 muted">{{ __('Sign in to manage your bookings and availability.') }}</p>

    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1.5" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Password')" />
                @if (Route::has('password.request'))
                    <a class="link text-sm" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
                @endif
            </div>
            <x-text-input id="password" class="mt-1.5" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex cursor-pointer items-center gap-2.5">
            <input id="remember_me" type="checkbox" class="checkbox" name="remember">
            <span class="text-sm text-ink-2">{{ __('Keep me signed in') }}</span>
        </label>

        <x-primary-button class="w-full">{{ __('Sign in') }}</x-primary-button>
    </form>

    <p class="mt-8 text-sm text-ink-3">{{ __('No account? Ask your team administrator for an invitation.') }}</p>
</x-guest-layout>
