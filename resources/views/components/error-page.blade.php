@props([
    'code',
    // Translation keys: translated here, once the error page has resolved its language.
    'title',
    'message',
    'icon' => 'exclamation-triangle',
    // Wall screens have nobody to press "reload": retry by themselves.
    'autoRetry' => false,
])

@php
    // Some errors (e.g. "page not found") never reach the SetLocale middleware: apply the same rules here.
    $errorLocale = auth()->user()?->locale
        ?? (request()->hasSession() ? request()->session()->get('locale') : null)
        ?? \App\Models\AppSetting::current()->locale();
    if (array_key_exists((string) $errorLocale, \App\Models\AppSetting::LOCALES)) {
        app()->setLocale($errorLocale);
    }

    $onKiosk = request()->is('kiosk', 'kiosk/*', 'rooms/*/mobile');
    $retrySeconds = 30;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full">
    <head>
        @include('partials.head', ['title' => __($title)])
        <meta name="robots" content="noindex" />
    </head>
    <body class="h-full bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 h-96 bg-linear-to-b from-brand/10 to-transparent"></div>

        <main class="relative flex min-h-full flex-col items-center justify-center px-6 py-16 text-center">
            <a href="{{ url('/') }}" class="mb-12 flex items-center gap-3">
                <x-brand-mark size="sm" />
                <span class="font-semibold tracking-tight">{{ $branding->displayName() }}</span>
            </a>

            <div class="relative">
                <p aria-hidden="true" class="text-[clamp(6rem,22vw,11rem)] leading-none font-semibold tracking-tighter text-zinc-200 tabular-nums select-none dark:text-white/[0.06]">{{ $code }}</p>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="flex size-16 items-center justify-center rounded-2xl bg-white shadow-lg ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">
                        <flux:icon :name="$icon" class="size-8 text-brand" />
                    </span>
                </div>
            </div>

            <h1 class="mt-8 text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ __($title) }}</h1>
            <p class="mt-3 max-w-md text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __($message) }}</p>

            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                @if (trim($slot) !== '')
                    {{ $slot }}
                @else
                    <flux:button variant="primary" :href="url('/')" icon="home">{{ __('Back to home') }}</flux:button>
                    <flux:button variant="ghost" icon="arrow-left" :data-home="url('/')" x-data x-on:click="history.length > 1 ? history.back() : (window.location.href = $el.dataset.home)">{{ __('Go back') }}</flux:button>
                @endif
            </div>

            @if ($autoRetry && $onKiosk)
                <p
                    class="mt-8 text-sm text-zinc-500"
                    x-data="{ left: {{ $retrySeconds }} }"
                    x-init="setInterval(() => { if (--left <= 0) window.location.reload() }, 1000)"
                >
                    <span x-text="@js(__('New attempt in')) + ' ' + left + ' s'">{{ __('New attempt in') }} {{ $retrySeconds }} s</span>
                </p>
            @endif

            <p class="mt-12 text-xs text-zinc-400 dark:text-zinc-600">{{ __('Error :code', ['code' => $code]) }}</p>
        </main>

        @fluxScripts
    </body>
</html>
