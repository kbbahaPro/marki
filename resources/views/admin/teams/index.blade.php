<x-app-layout :title="__('Teams')">
    <x-slot name="header">
        <h1 class="page-title">{{ __('Teams') }}</h1>
        <p class="mt-1 muted">{{ __('Group members to share round-robin and collective event types.') }}</p>
    </x-slot>

    <div class="pb-16 pt-6">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'team-deleted')
                <div class="alert-info" role="status"><x-icon name="check" class="mt-0.5 h-4 w-4" /> {{ __('Team deleted.') }}</div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="card self-start overflow-hidden lg:col-span-2" aria-labelledby="teams-heading">
                    <h2 id="teams-heading" class="border-b border-line px-5 py-4 section-title">{{ __('All teams') }}</h2>
                    @if ($teams->isEmpty())
                        <div class="flex flex-col items-center px-6 py-14 text-center">
                            <div class="flex h-12 w-20 items-center justify-center rounded-full bg-primary-soft text-primary-ink"><x-icon name="users" /></div>
                            <p class="mt-4 font-semibold text-ink">{{ __('No teams yet') }}</p>
                            <p class="mt-1 muted">{{ __('Create one with the form.') }}</p>
                        </div>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($teams as $team)
                                <li>
                                    <a href="{{ route('admin.teams.edit', $team) }}" class="group flex items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-2/60">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-soft font-display text-sm font-bold text-primary-ink" aria-hidden="true">{{ mb_strtoupper(mb_substr($team->name, 0, 1)) }}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-semibold text-ink">{{ $team->name }}</span>
                                            <span class="block truncate text-sm text-ink-3">/team/{{ $team->slug }}</span>
                                        </span>
                                        <span class="pill-neutral">{{ trans_choice(':count member|:count members', $team->members_count) }}</span>
                                        <x-icon name="chevron-right" class="h-4 w-4 text-ink-3 transition-transform group-hover:translate-x-0.5" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="card self-start p-5 sm:p-6" aria-labelledby="new-team-heading"
                         x-data="{ name: @js(old('name', '')), slug: @js(old('slug', '')), touched: @js((bool) old('slug')) }">
                    <h2 id="new-team-heading" class="section-title">{{ __('New team') }}</h2>
                    <form method="post" action="{{ route('admin.teams.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input id="name" name="name" class="mt-1.5" x-model="name" maxlength="100" required
                                          @input="if (! touched) slug = name.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60)" />
                            <x-input-error class="mt-2" :messages="$errors->get('name')" />
                        </div>
                        <div>
                            <x-input-label for="slug" :value="__('URL')" />
                            <div class="mt-1.5 flex overflow-hidden rounded-xl border border-line bg-surface focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                                <span class="inline-flex items-center border-r border-line bg-surface-2 px-3 text-sm text-ink-3">/team/</span>
                                <input id="slug" name="slug" x-model="slug" @input="touched = true" maxlength="60" required class="block w-full min-w-0 flex-1 border-0 bg-transparent text-ink focus:ring-0">
                            </div>
                            <x-input-error class="mt-2" :messages="$errors->get('slug')" />
                        </div>
                        <div>
                            <x-input-label for="description" :value="__('Description (optional)')" />
                            <textarea id="description" name="description" rows="3" maxlength="2000" class="input mt-1.5">{{ old('description') }}</textarea>
                            <x-input-error class="mt-2" :messages="$errors->get('description')" />
                        </div>
                        <x-primary-button class="w-full"><x-icon name="plus" class="h-4 w-4" /> {{ __('Create team') }}</x-primary-button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
