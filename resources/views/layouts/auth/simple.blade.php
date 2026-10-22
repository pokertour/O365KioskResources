<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 h-80 bg-linear-to-b from-brand/10 to-transparent"></div>

        <x-locale-switcher class="absolute end-5 top-5 z-10" />

        <div class="relative flex min-h-dvh flex-col items-center justify-center px-5 py-12">
            <a href="{{ route('home') }}" class="mb-8 flex flex-col items-center gap-3" wire:navigate>
                <x-brand-mark size="lg" />
                <span class="text-base font-semibold tracking-tight">{{ $branding->displayName() }}</span>
            </a>

            <div class="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-7 shadow-sm dark:border-white/[0.08] dark:bg-zinc-900">
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist

        @fluxScripts
    </body>
</html>
