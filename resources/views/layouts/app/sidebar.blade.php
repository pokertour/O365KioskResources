<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        @php
            $navItem = 'h-9 rounded-lg text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 data-current:bg-white data-current:font-medium data-current:text-zinc-900 data-current:shadow-xs data-current:ring-1 data-current:ring-zinc-200 data-current:[&_svg]:text-brand dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white dark:data-current:bg-white/[0.07] dark:data-current:text-white dark:data-current:ring-white/10';
            $groupLabel = 'px-2 pb-1 pt-6 text-[11px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500';
        @endphp

        <flux:sidebar
            sticky
            collapsible="mobile"
            class="border-e border-zinc-200 bg-zinc-50! lg:min-h-dvh dark:border-white/[0.06] dark:bg-zinc-950!"
        >
            <flux:sidebar.header class="pb-2">
                <x-app-logo :href="route('dashboard')" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="gap-0.5 pt-2">
                @if (auth()->user()->is_admin)
                    <flux:sidebar.item icon="building-office-2" :href="route('admin.rooms.index')" :current="request()->routeIs('admin.rooms.*')" wire:navigate :class="$navItem">
                        {{ __('Rooms') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="chart-bar" :href="route('admin.stats')" :current="request()->routeIs('admin.stats')" wire:navigate :class="$navItem">
                        {{ __('Statistics') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="megaphone" :href="route('admin.announcements')" :current="request()->routeIs('admin.announcements')" wire:navigate :class="$navItem">
                        {{ __('Announcements') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clock" :href="route('admin.activity')" :current="request()->routeIs('admin.activity')" wire:navigate :class="$navItem">
                        {{ __('Activity') }}
                    </flux:sidebar.item>

                    <div class="{{ $groupLabel }}">{{ __('Settings') }}</div>
                    <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.general')" :current="request()->routeIs('admin.general')" wire:navigate :class="$navItem">
                        {{ __('General') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="tv" :href="route('admin.screens')" :current="request()->routeIs('admin.screens')" wire:navigate :class="$navItem">
                        {{ __('Screens') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="swatch" :href="route('admin.branding')" :current="request()->routeIs('admin.branding')" wire:navigate :class="$navItem">
                        {{ __('Branding') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="bell" :href="route('admin.notifications')" :current="request()->routeIs('admin.notifications')" wire:navigate :class="$navItem">
                        {{ __('Notifications') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="cloud" :href="route('admin.microsoft')" :current="request()->routeIs('admin.microsoft')" wire:navigate :class="$navItem">
                        Microsoft 365
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate :class="$navItem">
                        {{ __('Administrators') }}
                    </flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="user-circle" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate :class="$navItem">
                        {{ __('My account') }}
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" />
        </flux:sidebar>

        <flux:header class="border-b border-zinc-200 bg-white/90 backdrop-blur lg:hidden dark:border-white/[0.06] dark:bg-zinc-950/90">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <x-app-logo class="ms-2" :href="route('dashboard')" wire:navigate />
            <flux:spacer />
            <x-desktop-user-menu compact />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist

        @fluxScripts
    </body>
</html>
