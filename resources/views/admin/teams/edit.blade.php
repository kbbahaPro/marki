@php
    use App\Enums\TeamRole;

    $messages = [
        'team-created' => __('Team created. Now add its members.'),
        'team-updated' => __('Team saved.'),
        'member-added' => __('Member added.'),
        'member-updated' => __('Role updated.'),
        'member-removed' => __('Member removed.'),
    ];
@endphp

<x-app-layout :title="$team->name">
    <x-slot name="header">
        <a href="{{ route('admin.teams.index') }}" class="btn-ghost -ms-3 min-h-0 py-1.5 text-sm"><x-icon name="arrow-left" class="h-4 w-4" />{{ __('All teams') }}</a>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="page-title">{{ $team->name }}</h1>
                <a href="{{ $team->publicUrl() }}" target="_blank" rel="noopener" class="link mt-1 inline-flex items-center gap-1 text-sm">{{ preg_replace('#^https?://#', '', $team->publicUrl()) }} <x-icon name="external" class="h-3.5 w-3.5" /></a>
            </div>
            <a href="{{ route('teams.bookings', $team) }}" class="btn-secondary"><x-icon name="calendar" class="h-4 w-4" /> {{ __('Bookings') }}</a>
        </div>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (isset($messages[session('status')]))
                <div class="alert-success" role="status"><x-icon name="check" class="mt-0.5 h-4 w-4" /> {{ $messages[session('status')] }}</div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                {{-- Members --}}
                <section class="card self-start overflow-hidden lg:col-span-2" aria-labelledby="members-heading">
                    <div class="flex items-center justify-between border-b border-line px-5 py-4">
                        <h2 id="members-heading" class="section-title">{{ __('Members') }}</h2>
                        <span class="pill-neutral">{{ $team->members->count() }}</span>
                    </div>

                    @if ($team->members->isEmpty())
                        <p class="px-5 py-10 text-center muted">{{ __('No members yet. Add people below.') }}</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($team->members as $member)
                                <li class="flex flex-wrap items-center gap-4 px-5 py-3">
                                    <x-avatar :user="$member" size="md" decorative />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold text-ink">{{ $member->name }}</span>
                                        <span class="block truncate text-sm text-ink-3">{{ $member->headline() ?? $member->email }}</span>
                                    </span>
                                    <form method="post" action="{{ route('admin.teams.members.update', [$team, $member]) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('patch')
                                        <label for="role-{{ $member->id }}" class="sr-only">{{ __('Role for :name', ['name' => $member->name]) }}</label>
                                        <select id="role-{{ $member->id }}" name="role" class="input min-h-[2.5rem] w-auto py-1.5 text-sm" onchange="this.form.requestSubmit()">
                                            @foreach (TeamRole::cases() as $role)
                                                <option value="{{ $role->value }}" @selected($member->pivot->role === $role)>{{ $role->label() }}</option>
                                            @endforeach
                                        </select>
                                        <noscript><button class="btn-ghost min-h-0 py-1.5 text-sm">{{ __('Save') }}</button></noscript>
                                    </form>
                                    <form method="post" action="{{ route('admin.teams.members.destroy', [$team, $member]) }}"
                                          onsubmit="return confirm(@js(__('Remove :name from :team?', ['name' => $member->name, 'team' => $team->name])))">
                                        @csrf
                                        @method('delete')
                                        <button class="btn-ghost min-h-0 h-10 w-10 p-0 hover:text-danger" aria-label="{{ __('Remove :name', ['name' => $member->name]) }}"><x-icon name="x" class="h-4 w-4" /></button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <form method="post" action="{{ route('admin.teams.members.store', $team) }}" class="flex flex-wrap items-end gap-3 border-t border-line bg-surface-2/50 px-5 py-4">
                        @csrf
                        <div class="min-w-[12rem] flex-1">
                            <x-input-label for="user_id" :value="__('Add a member')" />
                            <select id="user_id" name="user_id" class="input mt-1.5" required @disabled($candidates->isEmpty())>
                                @forelse ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}" @selected(old('user_id') == $candidate->id)>{{ $candidate->name }} ({{ $candidate->email }})</option>
                                @empty
                                    <option value="">{{ __('Everyone is already in this team') }}</option>
                                @endforelse
                            </select>
                        </div>
                        <div>
                            <x-input-label for="new_role" :value="__('Role')" />
                            <select id="new_role" name="role" class="input mt-1.5 w-auto">
                                @foreach (TeamRole::cases() as $role)
                                    <option value="{{ $role->value }}" @selected(old('role', TeamRole::Member->value) === $role->value)>{{ $role->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-primary-button :disabled="$candidates->isEmpty()"><x-icon name="plus" class="h-4 w-4" /> {{ __('Add') }}</x-primary-button>
                        <x-input-error class="w-full" :messages="$errors->get('user_id')" />
                    </form>
                </section>

                {{-- Details --}}
                <aside class="space-y-6">
                    <section class="card p-5 sm:p-6" aria-labelledby="details-heading">
                        <h2 id="details-heading" class="section-title">{{ __('Details') }}</h2>
                        <form method="post" action="{{ route('admin.teams.update', $team) }}" class="mt-4 space-y-4">
                            @csrf
                            @method('put')
                            <div>
                                <x-input-label for="name" :value="__('Name')" />
                                <x-text-input id="name" name="name" class="mt-1.5" :value="old('name', $team->name)" maxlength="100" required />
                                <x-input-error class="mt-2" :messages="$errors->get('name')" />
                            </div>
                            <div>
                                <x-input-label for="slug" :value="__('URL')" />
                                <div class="mt-1.5 flex overflow-hidden rounded-xl border border-line bg-surface focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                                    <span class="inline-flex items-center border-r border-line bg-surface-2 px-3 text-sm text-ink-3">/team/</span>
                                    <input id="slug" name="slug" value="{{ old('slug', $team->slug) }}" maxlength="60" required class="block w-full min-w-0 flex-1 border-0 bg-transparent text-ink focus:ring-0">
                                </div>
                                <p class="help">{{ __('Changing it breaks links you have already shared.') }}</p>
                                <x-input-error class="mt-2" :messages="$errors->get('slug')" />
                            </div>
                            <div>
                                <x-input-label for="description" :value="__('Description (optional)')" />
                                <textarea id="description" name="description" rows="3" maxlength="2000" class="input mt-1.5">{{ old('description', $team->description) }}</textarea>
                            </div>
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                        </form>
                    </section>

                    <section class="card p-5 sm:p-6">
                        <h2 class="section-title">{{ __('Delete team') }}</h2>
                        <p class="mt-1 muted">{{ __('Members keep their accounts; only the team is removed.') }}</p>
                        @if ($blocker = $team->deletionBlocker())
                            <p class="mt-4 text-sm text-ink-2">{{ $blocker }}</p>
                        @else
                            <form method="post" action="{{ route('admin.teams.destroy', $team) }}" class="mt-4"
                                  onsubmit="return confirm(@js(__('Delete the team :team?', ['team' => $team->name])))">
                                @csrf
                                @method('delete')
                                <x-danger-button>{{ __('Delete team') }}</x-danger-button>
                            </form>
                        @endif
                        <x-input-error class="mt-2" :messages="$errors->get('team')" />
                    </section>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
