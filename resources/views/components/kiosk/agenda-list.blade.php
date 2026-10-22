@props([
    'agenda',
    'state',
])

@php
    $past = array_values(array_filter($agenda['timed'], fn ($e) => $e['past']));
    // Upcoming meetings and free slots, in time order, with a “now” marker when nothing is in progress.
    $rows = collect($agenda['timed'])->reject(fn ($e) => $e['past'])->map(fn ($e) => ['type' => 'event'] + $e)
        ->concat(collect($agenda['free'])->map(fn ($s) => ['type' => 'free'] + $s))
        ->sortBy(fn ($r) => $r['start']->timestamp)
        ->values();
    $inProgress = collect($agenda['timed'])->contains('current', true);
@endphp

<div class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" x-data="{ showPast: false }">
    @if (count($past) > 0)
        <button
            type="button"
            x-on:click="showPast = ! showPast"
            class="mb-2 flex w-full items-center justify-between rounded-2xl px-4 py-2.5 text-base text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-white/5"
        >
            <span>{{ trans_choice(':count meeting ended|:count meetings ended', count($past)) }}</span>
            <flux:icon.chevron-down class="size-[1.1em] transition" ::class="showPast && 'rotate-180'" />
        </button>
        <div x-show="showPast" x-collapse x-cloak>
            @foreach ($past as $event)
                <x-kiosk.agenda-row :event="$event" :state="$state" wire:key="past-{{ md5($event['id']) }}" />
            @endforeach
        </div>
    @endif

    @if (! $inProgress)
        <div class="my-2 flex items-center gap-3 px-4" aria-hidden="true">
            <span class="size-2.5 rounded-full bg-busy"></span>
            <span class="h-0.5 flex-1 bg-busy/60"></span>
        </div>
    @endif

    @foreach ($rows as $row)
        @if ($row['type'] === 'event')
            <x-kiosk.agenda-row :event="$row" :state="$state" wire:key="ev-{{ md5($row['id']) }}{{ $row['current'] ? '-focus' : '' }}" />
        @elseif ($agenda['interactive'])
            <button
                type="button"
                wire:key="free-{{ $row['start']->timestamp }}"
                wire:click="openBookingForm('{{ $row['start']->format('H:i') }}', '{{ $row['start']->addMinutes(min(60, $row['minutes']))->format('H:i') }}')"
                class="mb-2 flex w-full items-center gap-5 rounded-2xl border-2 border-dashed border-available/35 px-4 py-2.5 text-start text-available transition hover:border-available/70 hover:bg-available/10"
            >
                <span class="w-[5.5em] shrink-0 text-lg font-semibold tabular-nums">{{ fmt_time($row['start']) }}</span>
                <span class="flex items-center gap-1.5 text-lg font-semibold"><flux:icon.plus class="size-[1.1em]" />{{ __('Free') }} · {{ fmt_duration($row['minutes']) }}</span>
            </button>
        @else
            <div wire:key="free-{{ $row['start']->timestamp }}" class="mb-2 flex items-center gap-5 rounded-2xl border-2 border-dashed border-available/25 px-4 py-2.5 text-available/80">
                <span class="w-[5.5em] shrink-0 text-lg font-semibold tabular-nums">{{ fmt_time($row['start']) }}</span>
                <span class="text-lg font-medium">{{ __('Free') }} · {{ fmt_duration($row['minutes']) }}</span>
            </div>
        @endif
    @endforeach

    @if (count($agenda['timed']) === 0 && count($agenda['free']) === 0)
        <div class="flex h-full flex-col items-center justify-center py-8 text-center">
            <div class="flex size-16 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-white/5">
                <flux:icon.calendar-days class="size-8 text-zinc-400" />
            </div>
            <p class="mt-4 text-xl font-medium">{{ __('No meetings scheduled') }}</p>
            <p class="mt-1 text-base text-zinc-500 dark:text-zinc-400">{{ __('The room is free for the day.') }}</p>
        </div>
    @endif
</div>
