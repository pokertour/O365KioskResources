<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-50 antialiased">
        {{ $slot }}
        @persist('toast')
            <flux:toast position="bottom end" />
        @endpersist
        @fluxScripts
    </body>
</html>
