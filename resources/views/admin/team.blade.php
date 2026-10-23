<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Team') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('Invite a team member') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('They will receive an email with a link to set up their account.') }}</p>
                    </header>

                    <form method="post" action="{{ route('team.invite') }}" class="mt-6 space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="email" :value="__('Email')" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                            <x-input-error class="mt-2" :messages="$errors->get('email')" />
                        </div>

                        <label for="is_admin" class="inline-flex items-center">
                            <input id="is_admin" type="checkbox" name="is_admin" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('is_admin'))>
                            <span class="ms-2 text-sm text-gray-600">{{ __('Can manage the team (admin)') }}</span>
                        </label>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Send invitation') }}</x-primary-button>

                            @if (session('status') === 'invitation-sent')
                                <p class="text-sm text-gray-600">{{ __('Invitation sent.') }}</p>
                            @elseif (session('status') === 'invitation-revoked')
                                <p class="text-sm text-gray-600">{{ __('Invitation revoked.') }}</p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>

            @if ($invitations->isNotEmpty())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('Pending invitations') }}</h2>
                    <ul class="mt-4 divide-y divide-gray-100">
                        @foreach ($invitations as $invitation)
                            <li class="py-3 flex items-center justify-between gap-4">
                                <div class="text-sm">
                                    <div class="font-medium text-gray-900">{{ $invitation->email }}</div>
                                    <div class="text-gray-500">
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

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h2 class="text-lg font-medium text-gray-900">{{ __('Members') }}</h2>
                <ul class="mt-4 divide-y divide-gray-100">
                    @foreach ($members as $member)
                        <li class="py-3 text-sm">
                            <span class="font-medium text-gray-900">{{ $member->name }}</span>
                            <span class="text-gray-500">· {{ $member->email }} · /{{ $member->username }} · {{ $member->timezone }}</span>
                            @if ($member->is_admin)
                                <span class="ms-2 inline-flex items-center rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ __('Admin') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
