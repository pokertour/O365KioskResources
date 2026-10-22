@props([
    'agenda',
    'state',
])

@php
    /** @var \Carbon\CarbonImmutable $from */
    $from = $agenda['from'];
    $to = $agenda['to'];
    $span = max(1, $from->diffInSeconds($to));
    $pct = fn ($t) => round(max(0, min(100, $from->diffInSeconds($t, false) / $span * 100)), 3);
    $hours = (int) round($span / 3600);
    // At least 5rem per hour; the column scrolls when the day doesn't fit.
    $minHeight = $hours * 5;
@endphp

<div
    wire:key="timeline-{{ $from->timestamp }}-{{ $to->timestamp }}"
    class="relative -mx-2 min-h-0 flex-1 overflow-y-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    x-data="{
        from: {{ $from->getTimestampMs() }},
        to: {{ $to->getTimestampMs() }},
        pct: null,
        update() {
            const p = (Date.now() - this.from) / (this.to - this.from) * 100;
            this.pct = p >= 0 && p <= 100 ? p : null;
        },
    }"
    x-init="
        update();
        setInterval(() => update(), 15000);
        {{-- Start with “now” in the upper third of the column. --}}
        $nextTick(() => { if (pct !== null) $el.scrollTop = $refs.canvas.offsetHeight * pct / 100 - $el.clientHeight / 3 });
    "
>
    <div class="relative" style="height: max(100%, {{ $minHeight }}rem)">
        <div x-ref="canvas" class="absolute inset-x-0 top-3 bottom-3">
            {{-- Hour grid --}}
            @for ($h = 0; $h <= $hours; $h++)
                @php
                    $at = $from->addHours($h);
                @endphp
                <div class="absolute inset-x-0 flex items-center" style="top: {{ $pct($at) }}%">
                    <span class="w-[4.5em] shrink-0 -translate-y-1/2 pe-3 text-end text-sm tabular-nums text-zinc-400 dark:text-zinc-500">{{ fmt_time($at) }}</span>
                    <span class="h-px flex-1 -translate-y-1/2 bg-zinc-200 dark:bg-white/[0.07]"></span>
                </div>
            @endfor

            <div class="absolute inset-y-0 start-[4.5em] end-0">
                {{-- Free slots: tap to book --}}
                @foreach ($agenda['free'] as $slot)
                    @php
                        $thin = $slot['minutes'] < 30;
                        $style = sprintf('top: calc(%s%% + 2px); height: calc(%s%% - 4px)', $pct($slot['start']), $pct($slot['end']) - $pct($slot['start']));
                    @endphp
                    @if ($agenda['interactive'])
                        <button
                            type="button"
                            wire:key="free-{{ $slot['start']->timestamp }}"
                            wire:click="openBookingForm('{{ $slot['start']->format('H:i') }}', '{{ $slot['start']->addMinutes(min(60, $slot['minutes']))->format('H:i') }}')"
                            @class([
                                'group absolute inset-x-0 flex overflow-hidden rounded-xl border-2 border-dashed border-available/35 bg-[repeating-linear-gradient(-45deg,color-mix(in_oklab,var(--color-available)_7%,transparent)_0_8px,transparent_8px_16px)] px-3 text-start text-available transition hover:border-available/70 hover:bg-available/10 active:scale-[0.99]',
                                'items-center' => $thin,
                                'items-start py-1.5' => ! $thin,
                            ])
                            style="{{ $style }}"
                        >
                            <span @class(['flex items-center gap-1.5 font-semibold', 'text-sm' => $thin, 'text-base' => ! $thin])>
                                <flux:icon.plus class="size-[1.1em]" />
                                {{ __('Free') }} · {{ fmt_duration($slot['minutes']) }}
                            </span>
                        </button>
                    @else
                        <div
                            wire:key="free-{{ $slot['start']->timestamp }}"
                            @class([
                                'absolute inset-x-0 flex overflow-hidden rounded-xl border-2 border-dashed border-available/25 px-3 font-medium text-available/80',
                                'items-center text-sm' => $thin,
                                'items-start py-1.5 text-base' => ! $thin,
                            ])
                            style="{{ $style }}"
                        >
                            {{ __('Free') }} · {{ fmt_duration($slot['minutes']) }}
                        </div>
                    @endif
                @endforeach

                {{-- Meetings --}}
                @foreach ($agenda['timed'] as $event)
                    @php
                        $minutes = (int) $event['start']->diffInMinutes($event['end']);
                        // Under ~50 min there is room for a single line only.
                        $compact = $minutes < 50;
                        $width = 100 / $event['cols'];
                    @endphp
                    <article
                        wire:key="tl-{{ md5($event['id']) }}"
                        wire:click="openEvent({{ \Illuminate\Support\Js::from($event['id']) }})"
                        role="button"
                        @class([
                            'absolute z-10 flex cursor-pointer overflow-hidden rounded-xl px-3 transition active:scale-[0.99]',
                            'items-center gap-3 py-0.5' => $compact,
                            'flex-col py-2' => ! $compact,
                            'bg-zinc-100 text-zinc-400 dark:bg-white/[0.04] dark:text-zinc-500' => $event['past'],
                            'bg-busy text-busy-foreground shadow-lg shadow-busy/25' => $event['current'],
                            'border-s-4 border-soon bg-soon/15 dark:bg-soon/20' => $event['next'] && $state === 'soon',
                            'border-s-4 border-brand bg-brand/10 dark:bg-brand/20' => ! $event['past'] && ! $event['current'] && ! ($event['next'] && $state === 'soon'),
                        ])
                        style="top: calc({{ $pct($event['start']) }}% + 2px); height: calc({{ $pct($event['end']) - $pct($event['start']) }}% - 4px); inset-inline-start: calc({{ $width * $event['col'] }}% + {{ $event['col'] ? 2 : 0 }}px); width: calc({{ $width }}% - {{ $event['cols'] > 1 ? 2 : 0 }}px)"
                    >
                        <p @class(['flex min-w-0 items-center gap-1.5 font-semibold', 'text-sm' => $minutes < 25, 'text-base' => $compact && $minutes >= 25, 'text-lg leading-snug' => ! $compact])>
                            <span class="truncate">{{ $event['subject'] }}</span>
                            @if ($event['online'] ?? null)
                                <flux:icon.video-camera class="size-[0.9em] shrink-0 opacity-70" />
                            @endif
                            @if ($event['editable'])
                                <flux:icon.pencil-square class="size-[0.9em] shrink-0 opacity-70" />
                            @endif
                        </p>
                        <p @class([
                            'shrink-0 truncate tabular-nums',
                            'text-sm opacity-80' => $compact,
                            'text-base opacity-80' => ! $compact,
                        ])>
                            {{ fmt_time($event['start']) }} – {{ fmt_time($event['end']) }}@if (! $compact && $event['organizer']) · {{ $event['organizer'] }}@endif
                        </p>
                    </article>
                @endforeach
            </div>

            {{-- “Now” marker, moved by the browser between two polls --}}
            <div
                {{-- Drawn under the meetings so it never hides a title; the dot stays visible in the hour gutter. --}}
                class="pointer-events-none absolute inset-x-0 z-0 flex -translate-y-1/2 items-center"
                x-show="pct !== null"
                x-cloak
                :style="`top: ${pct}%`"
            >
                <span class="ms-[3.9em] size-3 rounded-full bg-busy ring-4 ring-white dark:ring-zinc-900"></span>
                <span class="h-0.5 flex-1 bg-busy"></span>
            </div>
        </div>
    </div>
</div>
