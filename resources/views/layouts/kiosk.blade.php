<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-50">
        {{ $slot }}
        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist
        @fluxScripts
    </body>
</html>
