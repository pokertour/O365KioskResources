@props([
    'direction',
])

{{-- Wayfinding pictogram. Arrows are rotated from one shape so they all share the same weight. --}}
@php
    $rotation = [
        'up' => 0, 'up-right' => 45, 'right' => 90, 'down-right' => 135,
        'down' => 180, 'down-left' => 225, 'left' => 270, 'up-left' => 315,
    ][$direction] ?? null;
@endphp

<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
    @if ($rotation !== null)
        <g transform="rotate({{ $rotation }} 24 24)">
            <path d="M24 40V9" />
            <path d="M12 21 24 9l12 12" />
        </g>
    @elseif ($direction === 'stairs-up' || $direction === 'stairs-down')
        <path d="M5 41h9v-9h9v-9h9v-9h11" />
        @if ($direction === 'stairs-up')
            <path d="M22 13 31 4M24 4h7v7" stroke-width="3" />
        @else
            <path d="M31 4l-9 9M22 6v7h7" stroke-width="3" />
        @endif
    @elseif ($direction === 'elevator')
        <rect x="9" y="5" width="30" height="38" rx="3" />
        <path d="M24 5v38" />
        <path d="M14 17l3-4 3 4M28 13l3 4 3-4" stroke-width="3" />
    @endif
</svg>
