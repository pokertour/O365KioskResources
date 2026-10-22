<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{ $slot }}

        @persist('toast')
            <flux:toast position="bottom center" />
        @endpersist

        @fluxScripts
    </body>
</html>
