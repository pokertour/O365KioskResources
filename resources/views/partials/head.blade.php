<meta charset="utf-8" />
@if ($kiosk ?? false)
    {{-- Kiosk: no pinch / double-tap zoom on wall tablets. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
@else
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
@endif
<meta name="referrer" content="strict-origin-when-cross-origin" />

@php($brandName = $branding?->displayName() ?? config('app.name', 'Laravel'))
@php($brandLogoUrl = $branding?->logoUrl())

<title>{{ filled($title ?? null) ? __($title).' · '.$brandName : $brandName }}</title>

<meta name="application-name" content="{{ $brandName }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $brandName }}">
<meta name="theme-color" content="{{ $branding?->cssVariables()['--color-brand'] ?? '#2563eb' }}">
<link rel="manifest" href="/manifest.webmanifest">
@if ($brandLogoUrl)
    <link rel="icon" href="{{ $brandLogoUrl }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ $brandLogoUrl }}">
@else
    <link rel="icon" href="{{ $branding?->iconDataUri() ?? '/favicon.svg' }}" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@if ($branding)
    <style>:root{ {!! $branding->cssVariablesInline() !!} }</style>
@endif

@unless ($forceAppearance ?? false)
    @fluxAppearance
@endunless
