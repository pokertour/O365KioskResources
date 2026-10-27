@props([
    'size' => 'md',
])

@php
    // One logo per background; the page's `dark` class (admin theme, room screen theme) picks it.
    $lightLogo = $branding->logoUrlFor('light');
    $darkLogo = $branding->logoUrlFor('dark');
    $box = match ($size) {
        'sm' => 'size-8 rounded-lg',
        'lg' => 'size-14 rounded-2xl',
        'xl' => 'size-16 rounded-2xl',
        default => 'size-10 rounded-xl',
    };
@endphp

@if ($lightLogo)
    <span {{ $attributes->class([$box, 'flex shrink-0 items-center justify-center overflow-hidden']) }}>
        @if ($darkLogo === $lightLogo)
            <img src="{{ $lightLogo }}" alt="{{ $branding->displayName() }}" class="max-h-full max-w-full object-contain" />
        @else
            <img src="{{ $lightLogo }}" alt="{{ $branding->displayName() }}" class="max-h-full max-w-full object-contain dark:hidden" />
            <img src="{{ $darkLogo }}" alt="{{ $branding->displayName() }}" class="hidden max-h-full max-w-full object-contain dark:block" />
        @endif
    </span>
@else
    <span {{ $attributes->class([$box, 'flex shrink-0 items-center justify-center bg-brand text-brand-foreground']) }}>
        <x-app-logo-icon class="size-[62%]" />
    </span>
@endif
