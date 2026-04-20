<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@php($brandLogoUrl = $branding?->logoUrl())
<meta name="application-name" content="{{ $branding?->displayName() ?? config('app.name', 'Laravel') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $branding?->displayName() ?? config('app.name', 'Laravel') }}">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#fafafa">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a0a0a">
<link rel="manifest" href="/manifest.webmanifest">
@if ($brandLogoUrl)
    <link rel="icon" href="{{ $brandLogoUrl }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ $brandLogoUrl }}">
@else
    <link rel="icon" href="/icons/icon-192.svg" sizes="any">
    <link rel="apple-touch-icon" href="/icons/icon-192.svg">
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
