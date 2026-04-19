@props([
    'sidebar' => false,
])

@php
    $brandName = $branding->displayName();
    $logoUrl = $branding->logoUrl();
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$brandName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-700/80 bg-zinc-900 ring-1 ring-white/5">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="size-full object-contain p-0.5" width="32" height="32" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-emerald-400" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-700/80 bg-zinc-900 ring-1 ring-white/5">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="size-full object-contain p-0.5" width="32" height="32" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-emerald-400" />
            @endif
        </x-slot>
    </flux:brand>
@endif
