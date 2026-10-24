@php
    $user = Auth::user();
    $calendarBroken = $user->googleConnection && ! $user->googleConnection->isActive();
    $links = array_filter([
        ['route' => 'dashboard', 'match' => 'dashboard*', 'icon' => 'home', 'label' => __('Dashboard')],
        ['route' => 'event-types.index', 'match' => 'event-types.*', 'icon' => 'layers', 'label' => __('Event types')],
        ['route' => 'availability.edit', 'match' => 'availability.*', 'icon' => 'clock', 'label' => __('Availability')],
        ['route' => 'settings.calendar', 'match' => 'settings.calendar*', 'icon' => 'calendar', 'label' => __('Calendar'), 'alert' => $calendarBroken],
        $user->is_admin ? ['route' => 'members.index', 'match' => 'members.*', 'icon' => 'user', 'label' => __('Members')] : null,
        $user->is_admin ? ['route' => 'admin.teams.index', 'match' => 'admin.teams.*', 'icon' => 'users', 'label' => __('Teams')] : null,
    ]);
@endphp

<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-line bg-surface/85 backdrop-blur" aria-label="{{ __('Main') }}">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-8">
            <a href="{{ route('dashboard') }}" class="rounded-full">
                <x-application-logo />
            </a>

            <div class="hidden items-center gap-1 lg:flex">
                @foreach ($links as $link)
                    <x-nav-link :href="route($link['route'])" :active="request()->routeIs($link['match'])">
                        <x-icon :name="$link['icon']" class="h-4 w-4" />
                        {{ $link['label'] }}
                        @if ($link['alert'] ?? false)
                            <span class="h-2 w-2 rounded-full bg-danger" role="img" aria-label="{{ __('Needs reconnecting') }}"></span>
                        @endif
                    </x-nav-link>
                @endforeach
            </div>
        </div>

        <div class="hidden lg:block">
            <x-dropdown align="right" width="w-60">
                <x-slot name="trigger">
                    <button class="flex items-center gap-2 rounded-full py-1 pe-3 ps-1 text-sm font-medium text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink" aria-haspopup="menu">
                        <x-avatar :user="$user" size="sm" decorative />
                        <span class="max-w-[10rem] truncate">{{ $user->name }}</span>
                        <x-icon name="chevron-down" class="h-4 w-4" />
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="px-3 py-2">
                        <div class="truncate text-sm font-semibold text-ink">{{ $user->name }}</div>
                        <div class="truncate text-xs text-ink-3">{{ $user->email }}</div>
                    </div>
                    <div class="my-1 border-t border-line"></div>
                    <x-dropdown-link :href="route('public.profile', $user->username)" target="_blank" rel="noopener">
                        <x-icon name="external" class="h-4 w-4 text-ink-3" /> {{ __('View booking page') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('profile.edit')">
                        <x-icon name="user" class="h-4 w-4 text-ink-3" /> {{ __('Profile') }}
                    </x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm text-ink hover:bg-surface-2 focus:bg-surface-2 focus:outline-none">
                            <x-icon name="log-out" class="h-4 w-4 text-ink-3" /> {{ __('Log out') }}
                        </button>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>

        <button @click="open = ! open" class="btn-ghost lg:hidden" :aria-expanded="open" aria-controls="mobile-menu">
            <span class="sr-only">{{ __('Menu') }}</span>
            <x-icon name="menu" x-show="! open" />
            <x-icon name="x" x-show="open" x-cloak />
        </button>
    </div>

    <div id="mobile-menu" x-show="open" x-cloak x-transition.opacity class="border-t border-line bg-surface px-4 pb-4 pt-3 lg:hidden">
        <div class="space-y-1">
            @foreach ($links as $link)
                <x-responsive-nav-link :href="route($link['route'])" :active="request()->routeIs($link['match'])">
                    <x-icon :name="$link['icon']" />
                    {{ $link['label'] }}
                    @if ($link['alert'] ?? false)
                        <span class="pill-danger ms-auto">{{ __('Reconnect') }}</span>
                    @endif
                </x-responsive-nav-link>
            @endforeach
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <div class="px-3 pb-2">
                <div class="font-semibold text-ink">{{ $user->name }}</div>
                <div class="text-sm text-ink-3">{{ $user->email }}</div>
            </div>
            <x-responsive-nav-link :href="route('profile.edit')">
                <x-icon name="user" /> {{ __('Profile') }}
            </x-responsive-nav-link>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-base font-medium text-ink-2 hover:bg-surface-2 hover:text-ink">
                    <x-icon name="log-out" /> {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>
</nav>
