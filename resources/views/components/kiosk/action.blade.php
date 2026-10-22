@props([
    'variant' => 'solid',
])

{{-- Large touch target for the status panel (min. 3.5rem high, scales with the screen). --}}
<button
    type="button"
    wire:loading.attr="disabled"
    {{ $attributes->class([
        'min-h-14 rounded-2xl px-[1.4em] text-xl font-semibold transition active:scale-[0.97] disabled:opacity-60',
        'bg-white/95 text-zinc-900 shadow-lg shadow-black/10 hover:bg-white' => $variant === 'solid',
        'bg-black/15 ring-1 ring-current/30 hover:bg-black/25' => $variant === 'ghost',
    ]) }}
>
    {{ $slot }}
</button>
