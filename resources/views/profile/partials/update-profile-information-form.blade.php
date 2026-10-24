<section>
    <header>
        <h2 class="section-title">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-ink-2">
            {{ __('Your photo, name, title and company appear on your booking pages.') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        {{-- Photo, with an instant local preview before saving --}}
        <div x-data="{ preview: null, remove: false }" class="flex flex-wrap items-center gap-5">
            <div class="relative">
                <img x-show="preview" x-cloak :src="preview" decorative class="h-24 w-24 rounded-full object-cover">
                <div x-show="! preview && ! remove"><x-avatar :user="$user" size="xl" /></div>
                <span x-show="! preview && remove" x-cloak class="inline-flex h-24 w-24 items-center justify-center rounded-full bg-surface-2 text-3xl font-bold text-ink-3" aria-hidden="true">{{ $user->initials() }}</span>
            </div>
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <label class="btn-secondary min-h-0 cursor-pointer py-2 focus-within:ring-2 focus-within:ring-primary focus-within:ring-offset-2 focus-within:ring-offset-canvas">
                        <x-icon name="user" class="h-4 w-4" />
                        <span>{{ $user->avatar_path ? __('Change photo') : __('Upload photo') }}</span>
                        <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only"
                               @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null; remove = false">
                    </label>
                    @if ($user->avatar_path)
                        <label class="btn-ghost min-h-0 cursor-pointer py-2" x-show="! preview">
                            <input type="checkbox" name="remove_avatar" value="1" class="sr-only" x-model="remove">
                            <span x-text="remove ? @js(__('Keep photo')) : @js(__('Remove'))">{{ __('Remove') }}</span>
                        </label>
                    @endif
                </div>
                <p class="help">{{ __('JPG, PNG or WebP, at least 100×100 px. We crop it to a square.') }}</p>
                <x-input-error :messages="$errors->get('avatar')" />
            </div>
        </div>

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-input-label for="job_title" :value="__('Job title (optional)')" />
                <x-text-input id="job_title" name="job_title" type="text" class="mt-1 block w-full" :value="old('job_title', $user->job_title)" maxlength="100" autocomplete="organization-title" :placeholder="__('e.g. Head of Sales')" />
                <x-input-error class="mt-2" :messages="$errors->get('job_title')" />
            </div>
            <div>
                <x-input-label for="company" :value="__('Company (optional)')" />
                <x-text-input id="company" name="company" type="text" class="mt-1 block w-full" :value="old('company', $user->company)" maxlength="100" autocomplete="organization" :placeholder="config('app.name')" />
                <x-input-error class="mt-2" :messages="$errors->get('company')" />
            </div>
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-ink">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-ink-2 hover:text-ink rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-success">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username', $user->username)" required autocomplete="off" />
            <p class="mt-1 text-xs text-ink-3">{{ __('Changing this breaks booking links you have already shared.') }}</p>
            <x-input-error class="mt-2" :messages="$errors->get('username')" />
        </div>

        <div>
            <x-input-label for="timezone" :value="__('Time zone')" />
            <x-timezone-select id="timezone" name="timezone" class="mt-1 block w-full" :selected="old('timezone', $user->timezone)" required />
            <x-input-error class="mt-2" :messages="$errors->get('timezone')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-ink-2"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
