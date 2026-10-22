@php
    // The room page shares its room and screen so their own settings apply (theme, text size).
    $kioskRoom ??= null;
    $kioskScreen ??= null;
    $kioskDark = ($kioskScreen?->kiosk_theme ?? $kioskRoom?->kiosk_theme ?? $branding->kioskTheme()) === 'dark';
    $kioskSettings = match (true) {
        $kioskScreen !== null => \App\Models\AppSetting::forScreen($kioskScreen),
        $kioskRoom !== null => \App\Models\AppSetting::forRoom($kioskRoom),
        default => \App\Models\AppSetting::current(),
    };
    $kioskScale = $kioskSettings->textScale() / 100;
@endphp
<!DOCTYPE html>
<html
    lang="{{ app()->getLocale() }}"
    @class(['h-full', 'dark' => $kioskDark])
    {{--
        Everything on the screen is sized in rem, and the root size follows the screen:
        ~19px on a 1080p display, ~38px on 4K, never below 12px on small tablets.
        The admin "text size" setting multiplies it.
    --}}
    style="color-scheme: {{ $kioskDark ? 'dark' : 'light' }}; font-size: calc(clamp(12px, 0.8vmin + 0.55vmax, 48px) * {{ $kioskScale }})"
>
    <head>
        @include('partials.head', ['forceAppearance' => true, 'kiosk' => true])
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
    </head>
    <body
        class="h-full touch-manipulation overflow-hidden overscroll-none bg-zinc-50 font-sans text-zinc-900 antialiased select-none dark:bg-zinc-950 dark:text-zinc-50"
        x-data="{
            day: new Date().toDateString(),
            idle: false,
            idleTimer: null,
            wakeLock: null,
            async keepAwake() {
                try {
                    if ('wakeLock' in navigator && document.visibilityState === 'visible') {
                        this.wakeLock = await navigator.wakeLock.request('screen');
                    }
                } catch (e) {}
            },
            poke() {
                this.idle = false;
                clearTimeout(this.idleTimer);
                this.idleTimer = setTimeout(() => this.idle = true, 3000);
            },
            fullscreen() {
                const el = document.documentElement;
                if (! document.fullscreenElement && el.requestFullscreen) {
                    el.requestFullscreen({ navigationUI: 'hide' }).catch(() => {});
                }
            },
        }"
        x-init="
            {{-- Reload once the day changes so the screen never shows yesterday's agenda or stale assets. --}}
            setInterval(() => { if (new Date().toDateString() !== day) window.location.reload() }, 60000);
            keepAwake();
            document.addEventListener('visibilitychange', () => keepAwake());
            poke();
        "
        x-on:pointermove.window="poke()"
        x-on:pointerdown.window="poke(); fullscreen()"
        x-on:contextmenu.prevent
        x-on:dragstart.prevent
        :class="idle && 'cursor-none [&_*]:cursor-none'"
    >
        {{ $slot }}

        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist

        @fluxScripts
    </body>
</html>
