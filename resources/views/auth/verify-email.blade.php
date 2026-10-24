<x-guest-layout>
    <h1 class="text-3xl font-bold tracking-tight text-ink">{{ __('Verify your email') }}</h1>
    <div class="mb-8 mt-2 muted">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-success">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('Resend Verification Email') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-ink-2 hover:text-ink rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
