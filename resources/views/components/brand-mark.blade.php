@props([
    'size' => 'md',
])

@php
    $logoUrl = $branding->logoUrl();
    $box = match ($size) {
        'sm' => 'size-8 rounded-lg',
        'lg' => 'size-14 rounded-2xl',
        'xl' => 'size-16 rounded-2xl',
        default => 'size-10 rounded-xl',
    };
@endphp

@if ($logoUrl)
    <span {{ $attributes->class([$box, 'flex shrink-0 items-center justify-center overflow-hidden']) }}>
        <img src="{{ $logoUrl }}" alt="{{ $branding->displayName() }}" class="max-h-full max-w-full object-contain" />
    </span>
@else
    <span {{ $attributes->class([$box, 'flex shrink-0 items-center justify-center bg-brand text-brand-foreground']) }}>
        <x-app-logo-icon class="size-[62%]" />
    </span>
@endif
