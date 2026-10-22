<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        @include('partials.head')
        <meta name="description" content="{{ __('Microsoft 365 meeting room agendas shown on the screens in front of each room.') }}" />
    </head>
    <body class="min-h-dvh overflow-x-clip bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        @php
            // Screenshots exist in French and English; other languages fall back to English.
            $shots = 'images/screenshots/'.(app()->getLocale() === 'fr' ? 'fr' : 'en');
        @endphp

        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-[40rem] bg-linear-to-b from-brand/10 to-transparent"></div>

        <div class="relative mx-auto max-w-6xl px-5 sm:px-8">
            <header class="flex items-center justify-between py-6">
                <div class="flex items-center gap-3">
                    <x-brand-mark size="sm" />
                    <span class="font-semibold tracking-tight max-sm:sr-only">{{ $branding->displayName() }}</span>
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

            {{-- Hero --}}
            <section class="pt-12 pb-10 text-center sm:pt-20">
                <p class="text-sm font-semibold text-brand">{{ __('Meeting rooms') }}</p>
                <h1 class="mx-auto mt-3 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-6xl">
                    {{ __('Every room’s availability, at a glance.') }}
                </h1>
                <p class="mx-auto mt-5 max-w-2xl text-lg text-pretty text-zinc-600 dark:text-zinc-400">
                    {{ __('Screens in front of the rooms show the day’s agenda, synced with Microsoft 365, and let people book on the spot.') }}
                </p>
                <div class="mt-8 flex justify-center gap-3">
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')" wire:navigate icon-trailing="arrow-right">{{ __('Open the administration') }}</flux:button>
                    @else
                        <flux:button variant="primary" :href="route('login')" wire:navigate icon-trailing="arrow-right">{{ __('Log in') }}</flux:button>
                    @endauth
                </div>
            </section>

            {{-- Wall screen --}}
            <figure class="relative mx-auto max-w-5xl">
                <div aria-hidden="true" class="absolute inset-x-[8%] top-1/3 -bottom-6 -z-10 rounded-full bg-brand/25 blur-2xl"></div>
                <div class="rounded-[1.75rem] bg-zinc-900 p-2.5 shadow-2xl ring-1 ring-black/10 sm:p-3.5 dark:bg-zinc-800 dark:ring-white/10">
                    <img
                        src="{{ asset($shots.'/kiosk-landscape.webp') }}"
                        width="1920" height="1080"
                        alt="{{ __('Room screen showing a meeting in progress and the day’s timeline') }}"
                        class="block w-full rounded-[1.25rem]"
                        fetchpriority="high"
                    />
                </div>
                <div aria-hidden="true" class="mx-auto h-6 w-28 bg-linear-to-b from-zinc-300 to-zinc-200 dark:from-zinc-700 dark:to-zinc-800"></div>
                <div aria-hidden="true" class="mx-auto h-2 w-56 rounded-t-lg bg-zinc-300 dark:bg-zinc-700"></div>
                <figcaption class="sr-only">{{ __('Room screen') }}</figcaption>
            </figure>

            {{-- Features --}}
            <dl class="mt-20 grid gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 sm:grid-cols-3 dark:border-white/[0.08] dark:bg-white/[0.08]">
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

            {{-- Tablet & phone --}}
            <section class="mt-24 grid items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-balance">{{ __('On the wall, on a tablet, in your pocket.') }}</h2>
                    <p class="mt-4 text-lg text-pretty text-zinc-600 dark:text-zinc-400">{{ __('The screen adapts to any size and orientation, in a dark or light theme with your colours.') }}</p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            ['device-tablet', __('Tablets and wall screens'), __('Portrait or landscape, text sized for the viewing distance, night mode outside working hours.')],
                            ['qr-code', __('A QR code for phones'), __('People scan the screen to see the agenda and book from their phone.')],
                            ['wrench-screwdriver', __('Supervised'), __('Administrators are alerted when a screen goes offline or someone reports a problem.')],
                        ] as [$icon, $title, $text])
                            <li class="flex gap-4">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand dark:bg-brand/20">
                                    <flux:icon :name="$icon" class="size-5" />
                                </span>
                                <span>
                                    <span class="block font-semibold">{{ $title }}</span>
                                    <span class="mt-0.5 block text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="relative flex items-end justify-center gap-6 sm:gap-10">
                    <div aria-hidden="true" class="absolute inset-[15%] -z-10 rounded-full bg-available/20 blur-2xl"></div>
                    {{-- Tablet --}}
                    <div class="w-[62%] rounded-[1.6rem] bg-zinc-900 p-2 shadow-2xl ring-1 ring-black/10 sm:p-2.5 dark:bg-zinc-800 dark:ring-white/10">
                        <img
                            src="{{ asset($shots.'/kiosk-portrait.webp') }}"
                            width="1080" height="1440"
                            alt="{{ __('Tablet in portrait showing a free room with quick booking buttons') }}"
                            class="block w-full rounded-[1.15rem]"
                            loading="lazy"
                        />
                    </div>
                    {{-- Phone --}}
                    <div class="w-[30%] translate-y-6 rounded-[2rem] bg-zinc-900 p-1.5 shadow-2xl ring-1 ring-black/10 dark:bg-zinc-800 dark:ring-white/10">
                        <img
                            src="{{ asset($shots.'/mobile.webp') }}"
                            width="780" height="1688"
                            alt="{{ __('Phone view of a room agenda, opened from the QR code') }}"
                            class="block w-full rounded-[1.6rem]"
                            loading="lazy"
                        />
                    </div>
                </div>
            </section>

            {{-- Closing call to action --}}
            <section class="mt-28 mb-16 rounded-3xl bg-zinc-900 px-6 py-14 text-center text-white sm:px-12 dark:bg-white/[0.04] dark:ring-1 dark:ring-white/10">
                <h2 class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ __('Connected to Microsoft 365 in a few minutes.') }}</h2>
                <p class="mx-auto mt-3 max-w-xl text-zinc-300">{{ __('Import your rooms, pair the screens with a code, and you are done.') }}</p>
                <div class="mt-8 flex justify-center">
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')" wire:navigate icon-trailing="arrow-right">{{ __('Open the administration') }}</flux:button>
                    @else
                        <flux:button variant="primary" :href="route('login')" wire:navigate icon-trailing="arrow-right">{{ __('Log in') }}</flux:button>
                    @endauth
                </div>
            </section>

            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 py-8 text-sm text-zinc-500 dark:border-white/[0.08]">
                <span>{{ $branding->displayName() }} · {{ now()->year }}</span>
                <span class="font-mono text-xs">{{ app_version() }}</span>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
