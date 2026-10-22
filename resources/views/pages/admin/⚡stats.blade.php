<?php

use App\Services\MicrosoftGraphService;
use App\Services\RoomStats;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Statistics')] class extends Component {
    #[Url]
    public int $days = 30;

    /** Statistics need one Graph call per room: load them after the page is shown. */
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function updatedDays(): void
    {
        if (! in_array($this->days, [7, 30, 90], true)) {
            $this->days = 30;
        }
    }

    #[Computed]
    public function stats(): ?array
    {
        if (! $this->ready || ! app(MicrosoftGraphService::class)->isConfigured()) {
            return null;
        }

        return app(RoomStats::class)->compute(in_array($this->days, [7, 30, 90], true) ? $this->days : 30);
    }
}; ?>

@php
    $stats = $this->stats;
    $weekdays = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri')];
@endphp

<div wire:init="load">
    <x-admin.page-header icon="chart-bar" :title="__('Statistics')" :description="__('Room occupancy during working hours, Monday to Friday.')">
        <x-slot:actions>
            <flux:radio.group wire:model.live="days" variant="segmented" size="sm">
                <flux:radio :value="7" :label="__('7 days')" />
                <flux:radio :value="30" :label="__('30 days')" />
                <flux:radio :value="90" :label="__('90 days')" />
            </flux:radio.group>
        </x-slot:actions>
    </x-admin.page-header>

    @if (! app(\App\Services\MicrosoftGraphService::class)->isConfigured())
        <flux:callout icon="cloud" color="amber">
            <flux:callout.heading>{{ __('Microsoft 365 connection required') }}</flux:callout.heading>
            <flux:callout.text><flux:callout.link :href="route('admin.microsoft')" wire:navigate>{{ __('Configure the connection') }}</flux:callout.link></flux:callout.text>
        </flux:callout>
    @elseif (! $stats)
        <div class="grid gap-3 sm:grid-cols-4">
            @foreach (range(1, 4) as $i)
                <div class="h-20 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div>
            @endforeach
        </div>
        <div class="mt-6 h-72 animate-pulse rounded-xl bg-zinc-100 dark:bg-white/5"></div>
        <p class="mt-4 text-center text-sm text-zinc-500">{{ __('Reading the calendars…') }}</p>
    @else
        <div wire:loading.class="opacity-50" class="transition-opacity">
            {{-- Headline numbers --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    ['chart-pie', __('Average occupancy'), $stats['totals']['occupancy'].' %'],
                    ['calendar-days', __('Meetings'), number_format($stats['totals']['meetings'], 0, ',', ' ')],
                    ['clock', __('Hours booked'), number_format($stats['totals']['hours'], 0, ',', ' ').' h'],
                    ['device-tablet', __('Booked via the app'), number_format($stats['totals']['app_bookings'], 0, ',', ' ')],
                ] as [$icon, $label, $value])
                    <div class="flex items-center gap-3 rounded-2xl border border-zinc-200/80 bg-white px-3.5 py-4 sm:gap-4 sm:px-4 shadow-xs shadow-zinc-950/[0.03] dark:border-white/[0.07] dark:bg-white/[0.025]">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/20 ring-inset dark:text-[color-mix(in_oklab,var(--color-brand)_55%,white)]">
                            <flux:icon :name="$icon" class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-2xl leading-none font-semibold tracking-tight tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
                            <div class="mt-1 line-clamp-2 text-xs leading-tight font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Daily occupancy trend --}}
            @php
                $daily = $stats['daily'];
                $count = count($daily);
                $peak = $count ? max(array_column($daily, 'occupancy')) : 0;
                $ceiling = (int) min(100, max(20, ceil($peak / 20) * 20));
                $points = [];
                foreach ($daily as $k => $d) {
                    $points[] = [
                        'x' => $count > 1 ? $k / ($count - 1) * 100 : 50,
                        'y' => 100 - min($d['occupancy'], $ceiling) / $ceiling * 100,
                        'label' => ucfirst($d['date']->isoFormat('ddd D MMM')),
                        'value' => $d['occupancy'],
                    ];
                }
                $line = collect($points)->map(fn ($p) => round($p['x'] * 10, 2).','.round($p['y'] * 2, 2))->implode(' ');
            @endphp
            @if ($count > 1)
                <x-admin.card icon="arrow-trending-up" :title="__('Daily occupancy')" :description="__('Average of all rooms, working days only.')" class="mt-6">
                    <div class="flex gap-3" data-points="{{ json_encode($points) }}" x-data="{ i: null, points: JSON.parse($el.dataset.points) }">
                        <div class="flex h-48 w-8 shrink-0 flex-col justify-between py-0 text-right text-[11px] tabular-nums text-zinc-400 dark:text-zinc-500">
                            @foreach ([1, 0.75, 0.5, 0.25, 0] as $f)
                                <span class="-my-1.5 leading-3">{{ round($ceiling * $f) }} %</span>
                            @endforeach
                        </div>
                        <div class="relative h-48 flex-1">
                            {{-- Grid --}}
                            @foreach ([0, 25, 50, 75, 100] as $g)
                                <div class="absolute inset-x-0 border-t {{ $g === 100 ? 'border-zinc-300 dark:border-white/15' : 'border-dashed border-zinc-200/80 dark:border-white/[0.06]' }}" style="top: {{ $g }}%"></div>
                            @endforeach
                            <svg viewBox="0 0 1000 200" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible" aria-hidden="true">
                                <defs>
                                    <linearGradient id="occ-fill" x1="0" x2="0" y1="0" y2="1">
                                        <stop offset="0%" stop-color="var(--color-brand)" stop-opacity="0.28" />
                                        <stop offset="100%" stop-color="var(--color-brand)" stop-opacity="0" />
                                    </linearGradient>
                                </defs>
                                <polygon points="0,200 {{ $line }} 1000,200" fill="url(#occ-fill)" />
                                <polyline points="{{ $line }}" fill="none" stroke="var(--color-brand)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            </svg>
                            {{-- Hover layer: one column per day, wider than the marks --}}
                            <div class="absolute inset-0 flex" x-on:mouseleave="i = null">
                                @foreach ($points as $k => $p)
                                    <div class="h-full flex-1" x-on:mouseenter="i = {{ $k }}"></div>
                                @endforeach
                            </div>
                            <template x-if="i !== null">
                                <div class="pointer-events-none absolute inset-0">
                                    <div class="absolute inset-y-0 w-px bg-zinc-300 dark:bg-white/20" x-bind:style="`left: ${points[i].x}%`"></div>
                                    <div class="absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand ring-2 ring-white dark:ring-zinc-900" x-bind:style="`left: ${points[i].x}%; top: ${points[i].y}%`"></div>
                                    <div class="absolute top-0 z-20 rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg dark:bg-white dark:text-zinc-900"
                                         x-bind:style="`left: ${points[i].x}%; transform: translate(${points[i].x > 85 ? '-100%' : (points[i].x < 15 ? '0' : '-50%')}, calc(-100% - 0.5rem))`">
                                        <span x-text="points[i].label"></span> · <span class="font-semibold tabular-nums" x-text="`${points[i].value} %`"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="mt-2 flex justify-between ps-11 text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500">
                        <span>{{ $points[0]['label'] }}</span>
                        <span>{{ $points[intdiv($count, 2)]['label'] }}</span>
                        <span>{{ $points[$count - 1]['label'] }}</span>
                    </div>
                </x-admin.card>
            @endif

            <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                {{-- Occupancy per room: bars + table in one --}}
                <x-admin.card icon="chart-bar-square" :title="__('Occupancy by room')" :description="__(':from – :to', ['from' => $stats['from']->isoFormat('LL'), 'to' => $stats['to']->isoFormat('LL')])" :padded="false">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-100 text-left text-xs font-medium text-zinc-500 dark:border-white/[0.06]">
                                    <th class="px-5 py-2.5 font-medium">{{ __('Room') }}</th>
                                    <th class="w-[40%] px-3 py-2.5 font-medium">{{ __('Occupancy') }}</th>
                                    <th class="px-3 py-2.5 text-right font-medium">{{ __('Meetings') }}</th>
                                    <th class="px-3 py-2.5 text-right font-medium" title="{{ __('Average duration') }}">{{ __('Avg.') }}</th>
                                    <th class="px-3 py-2.5 text-right font-medium" title="{{ __('Booked via the app') }}">{{ __('App') }}</th>
                                    <th class="px-5 py-2.5 text-right font-medium" title="{{ __('Meetings ended early from the screen') }}">{{ __('Freed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (collect($stats['rooms'])->sortByDesc('occupancy') as $row)
                                    <tr wire:key="st-{{ $row['room']->id }}" class="border-b border-zinc-100 last:border-0 dark:border-white/[0.06]">
                                        <td class="max-w-40 truncate px-5 py-2.5 font-medium text-zinc-900 dark:text-white">
                                            <a href="{{ route('admin.rooms.show', $row['room']) }}" wire:navigate class="hover:underline">{{ $row['room']->name }}</a>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            @if ($row['error'])
                                                <span class="text-xs text-amber-600 dark:text-amber-400">{{ __('Unable to read the calendar') }}</span>
                                            @else
                                                <div class="group relative flex items-center gap-2">
                                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/[0.06]">
                                                        <div class="h-full rounded-full bg-brand" style="width: {{ min(100, $row['occupancy']) }}%"></div>
                                                    </div>
                                                    <span class="w-14 text-right whitespace-nowrap tabular-nums text-zinc-700 dark:text-zinc-300">{{ $row['occupancy'] }} %</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums text-zinc-700 dark:text-zinc-300">{{ $row['meetings'] }}</td>
                                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums text-zinc-700 dark:text-zinc-300">{{ $row['avg_minutes'] ? fmt_duration($row['avg_minutes']) : '—' }}</td>
                                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums text-zinc-700 dark:text-zinc-300">{{ $row['app_bookings'] }}</td>
                                        <td class="px-5 py-2.5 text-right whitespace-nowrap tabular-nums text-zinc-700 dark:text-zinc-300">{{ $row['ended_early'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-admin.card>

                {{-- Peak hours heatmap (single hue, light → dark) --}}
                <x-admin.card icon="fire" :title="__('Peak hours')" :description="__('Average occupancy of all rooms per time slot.')">
                    <div class="overflow-x-auto">
                        <div class="inline-grid min-w-full gap-[2px]" style="grid-template-columns: 2.5rem repeat({{ count($stats['hours']) }}, minmax(1.6rem, 1fr))">
                            <div></div>
                            @foreach ($stats['hours'] as $h)
                                <div class="pb-1 text-center text-[11px] tabular-nums text-zinc-500">{{ $h }}h</div>
                            @endforeach
                            @foreach ($weekdays as $wd => $label)
                                <div class="flex items-center text-xs font-medium text-zinc-500">{{ $label }}</div>
                                @foreach ($stats['hours'] as $h)
                                    @php
                                        $v = $stats['heatmap'][$wd][$h] ?? 0;
                                        $mix = $v > 0 ? round(12 + min(100, $v) * 0.8) : 0;
                                    @endphp
                                    <div class="group relative h-7 rounded-[4px] bg-zinc-100 dark:bg-white/[0.04]">
                                        <div class="absolute inset-0 rounded-[4px]" style="background: color-mix(in oklab, var(--color-brand) {{ $mix }}%, transparent)"></div>
                                        <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 hidden -translate-x-1/2 rounded-md bg-zinc-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow-lg group-hover:block dark:bg-white dark:text-zinc-900">
                                            {{ $label }} {{ $h }}h–{{ $h + 1 }}h · <span class="font-semibold tabular-nums">{{ $v }} %</span>
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2 text-xs text-zinc-500">
                        <span>0 %</span>
                        <span class="h-2 w-32 rounded-full" style="background: linear-gradient(to right, color-mix(in oklab, var(--color-brand) 12%, transparent), var(--color-brand))"></span>
                        <span>100 %</span>
                    </div>
                </x-admin.card>
            </div>
        </div>
    @endif
</div>
