@props([
    'event',
    'state',
])

@php
    $isSoon = $event['next'] && $state === 'soon';
@endphp

<article
    @if ($event['current'] || ($event['next'] && $state !== 'busy'))
        {{-- Keep the meeting in progress (or the next one) in view. --}}
        x-init="$nextTick(() => $el.scrollIntoView({ block: 'center', behavior: 'smooth' }))"
    @endif
    wire:click="openEvent({{ \Illuminate\Support\Js::from($event['id']) }})"
    role="button"
    {{ $attributes->class([
        'cursor-pointer active:scale-[0.99]',
        'mb-2 flex gap-5 rounded-2xl px-4 py-3 transition',
        'opacity-40' => $event['past'],
        'bg-zinc-100 ring-1 ring-zinc-200 dark:bg-white/[0.06] dark:ring-white/10' => $event['current'],
    ]) }}
>
    <div class="w-[5.5em] shrink-0 pt-0.5 tabular-nums">
        <div class="text-xl leading-tight font-semibold">{{ fmt_time($event['start']) }}</div>
        <div class="text-base text-zinc-500 dark:text-zinc-400">{{ fmt_time($event['end']) }}</div>
    </div>
    <div @class([
        'w-1 shrink-0 rounded-full',
        'bg-busy' => $event['current'],
        'bg-soon' => $isSoon,
        'bg-brand' => $event['next'] && ! $isSoon,
        'bg-zinc-200 dark:bg-white/10' => ! $event['current'] && ! $event['next'],
    ])></div>
    <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-3">
            <p class="flex min-w-0 items-center gap-2 text-xl font-medium">
                <span class="truncate">{{ $event['subject'] }}</span>
                @if ($event['online'] ?? null)
                    <flux:icon.video-camera class="size-[0.8em] shrink-0 text-zinc-400" />
                @endif
                @if ($event['editable'])
                    <flux:icon.pencil-square class="size-[0.8em] shrink-0 text-zinc-400" />
                @endif
            </p>
            @if ($event['current'])
                <span class="shrink-0 rounded-full bg-busy px-3 py-0.5 text-sm font-semibold text-busy-foreground">{{ __('Now') }}</span>
            @elseif ($isSoon)
                <span class="shrink-0 rounded-full bg-soon px-3 py-0.5 text-sm font-semibold text-soon-foreground">{{ __('Next') }}</span>
            @elseif ($event['next'])
                <span class="shrink-0 rounded-full bg-brand/10 px-3 py-0.5 text-sm font-semibold text-brand dark:bg-brand/20">{{ __('Next') }}</span>
            @endif
        </div>
        <p class="mt-0.5 truncate text-base text-zinc-500 dark:text-zinc-400">
            {{ fmt_duration((int) $event['start']->diffInMinutes($event['end'])) }}@if ($event['organizer']) · {{ $event['organizer'] }}@endif
        </p>
        @if ($event['current'])
            @php
                $done = max(0, min(100, $event['start']->diffInSeconds(now()) / max(1, $event['start']->diffInSeconds($event['end'])) * 100));
            @endphp
            <div class="mt-2 h-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                <div class="h-full rounded-full bg-busy" style="width: {{ round($done) }}%"></div>
            </div>
        @endif
    </div>
</article>
