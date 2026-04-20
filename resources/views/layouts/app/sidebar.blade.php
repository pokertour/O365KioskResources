<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-950 text-zinc-100 antialiased">
        {{-- Ordre Flux : header d’abord, puis sidebar, puis main — même grille que le starter (ligne header, puis sidebar | main alignés en haut). --}}
        <flux:header
            container
            class="border-b border-zinc-800/80 bg-zinc-950/95 backdrop-blur lg:hidden"
        >
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:sidebar
            sticky
            collapsible="mobile"
            class="min-h-0 border-e border-zinc-800/90 bg-zinc-950 !bg-zinc-950 shadow-[4px_0_24px_-8px_rgba(0,0,0,0.5)] lg:min-h-dvh"
        >
            <flux:sidebar.header class="border-b border-zinc-800/80 pb-3">
                <x-app-logo
                    :sidebar="true"
                    :href="auth()->user()->is_admin ? route('admin.rooms.index') : route('dashboard')"
                    wire:navigate
                />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="pt-2">
                <flux:sidebar.group heading="Administration" class="grid gap-0.5">
                    @if (auth()->user()->is_admin)
                        <flux:sidebar.item
                            icon="home"
                            :href="route('admin.rooms.index')"
                            :current="request()->routeIs('admin.rooms.*')"
                            wire:navigate
                            class="rounded-lg data-current:bg-emerald-500/15 data-current:text-emerald-100 data-current:[&>div>svg]:text-emerald-400"
                        >
                            Salles
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="user-group"
                            :href="route('admin.users.index')"
                            :current="request()->routeIs('admin.users.*')"
                            wire:navigate
                            class="rounded-lg data-current:bg-emerald-500/15 data-current:text-emerald-100 data-current:[&>div>svg]:text-emerald-400"
                        >
                            Administrateurs
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="photo"
                            :href="route('admin.branding')"
                            :current="request()->routeIs('admin.branding')"
                            wire:navigate
                            class="rounded-lg data-current:bg-emerald-500/15 data-current:text-emerald-100 data-current:[&>div>svg]:text-emerald-400"
                        >
                            Logo &amp; titre
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav></flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist

        @fluxScripts
    </body>
</html>
