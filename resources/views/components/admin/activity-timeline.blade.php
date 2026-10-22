@props([
    'logs',
    'showRoom' => true,
    'showIp' => false,
])

@php
    $tz = app(\App\Services\RoomSchedule::class)->timezone();
    $today = now($tz)->toDateString();
    $yesterday = now($tz)->subDay()->toDateString();
    $days = collect($logs)->groupBy(fn ($log) => $log->created_at->setTimezone($tz)->toDateString());
    $tones = [
        'brand' => 'bg-brand/10 text-brand ring-brand/25 dark:text-[color-mix(in_oklab,var(--color-brand)_55%,white)]',
        'available' => 'bg-available/10 text-available ring-available/25',
        'busy' => 'bg-busy/10 text-busy ring-busy/25',
        'soon' => 'bg-soon/10 text-soon ring-soon/25',
        'warning' => 'bg-amber-500/10 text-amber-600 ring-amber-500/25 dark:text-amber-400',
        'neutral' => 'bg-zinc-100 text-zinc-500 ring-zinc-200 dark:bg-white/[0.06] dark:text-zinc-400 dark:ring-white/10',
    ];
@endphp

<div {{ $attributes }}>
    @foreach ($days as $date => $entries)
        <section wire:key="day-{{ $date }}">
            <h3 class="sticky top-0 z-10 flex items-center gap-3 border-b border-zinc-100 bg-white/90 px-5 py-2 text-xs font-semibold text-zinc-500 backdrop-blur dark:border-white/[0.06] dark:bg-zinc-900/90">
                {{ $date === $today ? __('Today') : ($date === $yesterday ? __('Yesterday') : ucfirst(\Carbon\CarbonImmutable::parse($date, $tz)->isoFormat('dddd D MMMM'))) }}
                <span class="rounded-full bg-zinc-100 px-1.5 py-px text-[10px] font-medium tabular-nums text-zinc-500 dark:bg-white/[0.06]">{{ $entries->count() }}</span>
            </h3>
            <ol class="relative px-5 py-2">
                @foreach ($entries as $log)
                    <li wire:key="log-{{ $log->id }}" class="group relative flex gap-4 py-2.5">
                        @unless ($loop->last)
                            <span aria-hidden="true" class="absolute top-11 bottom-0 left-4 w-px -translate-x-1/2 bg-zinc-200 dark:bg-white/10"></span>
                        @endunless
                        <span class="relative flex size-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset {{ $tones[$log->tone()] }}">
                            <flux:icon :name="$log->icon()" variant="micro" />
                        </span>
                        <div class="min-w-0 flex-1 pt-1 text-sm">
                            <p class="text-zinc-900 dark:text-white">
                                <span class="font-medium">{{ $log->label() }}</span>
                                @if ($log->description) <span class="text-zinc-500 dark:text-zinc-400">· {{ $log->description }}</span> @endif
                            </p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500">
                                @if ($log->actor === 'screen')
                                    <span class="inline-flex items-center gap-1"><flux:icon.tv variant="micro" class="size-3.5" />{{ __('From the screen') }}</span>
                                @elseif ($log->actor === 'system')
                                    <span class="inline-flex items-center gap-1"><flux:icon.sparkles variant="micro" class="size-3.5" />{{ __('Automatic') }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="flex size-4 items-center justify-center rounded-full bg-zinc-200 text-[8px] font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $log->user?->initials() ?? '?' }}</span>
                                        {{ $log->user?->name ?? __('Administrator') }}
                                    </span>
                                @endif
                                @if ($showRoom && $log->room)
                                    <span class="text-zinc-300 dark:text-zinc-600">•</span>
                                    <a href="{{ route('admin.rooms.show', $log->room) }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"><flux:icon.building-office-2 variant="micro" class="size-3.5" />{{ $log->room->name }}</a>
                                @endif
                                @if ($showIp && $log->ip && $log->actor !== 'system')
                                    <span class="text-zinc-300 dark:text-zinc-600">•</span>
                                    <span class="font-mono text-[11px]">{{ $log->ip }}</span>
                                @endif
                            </p>
                        </div>
                        <time class="shrink-0 pt-1 text-xs tabular-nums text-zinc-400" datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->diffForHumans() }}">
                            {{ fmt_time($log->created_at->setTimezone($tz)) }}
                        </time>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
