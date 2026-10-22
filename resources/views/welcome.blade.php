<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        @include('partials.head')
        <meta name="description" content="{{ __('Microsoft 365 meeting room agendas shown on the screens in front of each room.') }}" />
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 h-[28rem] bg-linear-to-b from-brand/10 to-transparent"></div>

        <div class="relative mx-auto flex min-h-dvh max-w-5xl flex-col px-5 sm:px-8">
            <header class="flex items-center justify-between py-6">
                <div class="flex items-center gap-3">
                    <x-brand-mark size="sm" />
                    <span class="font-semibold tracking-tight">{{ $branding->displayName() }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-locale-switcher />
                    @auth
                        <flux:button size="sm" :href="route('dashboard')" wire:navigate icon-trailing="arrow-right">{{ __('Administration') }}</flux:button>
                    @else
                        <flux:button size="sm" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>
                    @endauth
                </div>
            </header>

            <main class="flex flex-1 flex-col justify-center py-16">
                <p class="text-sm font-semibold text-brand">{{ __('Meeting rooms') }}</p>
                <h1 class="mt-3 max-w-2xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                    {{ __('Every room’s availability, at a glance.') }}
                </h1>
                <p class="mt-5 max-w-xl text-lg text-pretty text-zinc-600 dark:text-zinc-400">
                    {{ __('Screens in front of the rooms show the day’s agenda, synced with Microsoft 365, and let people book on the spot.') }}
                </p>

                <dl class="mt-14 grid gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 sm:grid-cols-3 dark:border-white/[0.08] dark:bg-white/[0.08]">
                    @foreach ([
                        ['calendar-days', __('Always up to date'), __('The agenda syncs automatically with the room’s calendar.')],
                        ['cursor-arrow-rays', __('Book on the spot'), __('A free room can be booked in one tap, right from the screen.')],
                        ['lock-closed', __('Controlled access'), __('Each screen has its own secure link, revocable at any time.')],
                    ] as [$icon, $title, $text])
                        <div class="bg-white p-6 dark:bg-zinc-900">
                            <flux:icon :name="$icon" class="size-5 text-brand" />
                            <dt class="mt-4 font-semibold">{{ $title }}</dt>
                            <dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</dd>
                        </div>
                    @endforeach
                </dl>
            </main>

            <footer class="py-8 text-sm text-zinc-500">
                {{ $branding->displayName() }} · {{ now()->year }}
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
