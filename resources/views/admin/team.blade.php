<x-app-layout :title="__('Team')">
    <x-slot name="header">
        <h1 class="page-title">
            {{ __('Team') }}
        </h1>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="card p-5 sm:p-8">
                <section class="max-w-xl">
                    <header>
                        <h2 class="section-title">{{ __('Invite a team member') }}</h2>
                        <p class="mt-1 text-sm text-ink-2">{{ __('They will receive an email with a link to set up their account.') }}</p>
                    </header>

                    <form method="post" action="{{ route('team.invite') }}" class="mt-6 space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                            <x-input-error class="mt-2" :messages="$errors->get('email')" />
                        </div>

                        <label for="is_admin" class="inline-flex items-center">
                            <input id="is_admin" type="checkbox" name="is_admin" value="1" class="checkbox" @checked(old('is_admin'))>
                            <span class="ms-2 text-sm text-ink-2">{{ __('Can manage the team (admin)') }}</span>
                        </label>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Send invitation') }}</x-primary-button>

                            @if (session('status') === 'invitation-sent')
                                <p class="text-sm text-ink-2">{{ __('Invitation sent.') }}</p>
                            @elseif (session('status') === 'invitation-revoked')
                                <p class="text-sm text-ink-2">{{ __('Invitation revoked.') }}</p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>

            @if ($invitations->isNotEmpty())
                <div class="card p-5 sm:p-8">
                    <h2 class="section-title">{{ __('Pending invitations') }}</h2>
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($invitations as $invitation)
                            <li class="py-3 flex items-center justify-between gap-4">
                                <div class="text-sm">
                                    <div class="font-medium text-ink">{{ $invitation->email }}</div>
                                    <div class="text-ink-3">
                                        @if ($invitation->isExpired())
                                            {{ __('Expired') }}
                                        @else
                                            {{ __('Expires :when', ['when' => $invitation->expires_at->setTimezone(auth()->user()->timezone)->format('M j, Y H:i')]) }}
                                        @endif
                                        @if ($invitation->is_admin) · {{ __('Admin') }} @endif
                                    </div>
                                </div>
                                <form method="post" action="{{ route('team.invitations.revoke', $invitation) }}">
                                    @csrf
                                    @method('delete')
                                    <x-secondary-button type="submit">{{ __('Revoke') }}</x-secondary-button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card p-5 sm:p-8">
                <h2 class="section-title">{{ __('Members') }}</h2>
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($members as $member)
                        <li class="py-3 text-sm">
                            <span class="font-medium text-ink">{{ $member->name }}</span>
                            <span class="text-ink-3">· {{ $member->email }} · /{{ $member->username }} · {{ $member->timezone }}</span>
                            @if ($member->is_admin)
                                <span class="ms-2 inline-flex items-center rounded bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-ink">{{ __('Admin') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
