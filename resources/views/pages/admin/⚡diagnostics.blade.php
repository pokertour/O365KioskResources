<?php

use App\Services\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Diagnostics')] class extends Component {
    /** Results of the last live Microsoft 365 test, kept so polling does not call Graph again. */
    public ?array $microsoft = null;

    public ?string $testedAt = null;

    public function runTests(): void
    {
        $this->microsoft = app(SystemHealth::class)->checks(live: true)['microsoft'];
        $this->testedAt = now()->toIso8601String();
        unset($this->checks);
        Cache::forget('health:problem-count');
    }

    #[Computed]
    public function checks(): array
    {
        $checks = app(SystemHealth::class)->checks();
        if ($this->microsoft !== null) {
            $checks['microsoft'] = $this->microsoft;
        }

        return $checks;
    }

    #[Computed]
    public function rooms(): array
    {
        return app(SystemHealth::class)->roomSync();
    }

    #[Computed]
    public function counts(): array
    {
        $all = collect($this->checks)->flatten(1)->merge(collect($this->rooms)->where('status', 'warning')->map(fn () => ['status' => 'warning']));

        return ['error' => $all->where('status', 'error')->count(), 'warning' => $all->where('status', 'warning')->count()];
    }

    /** Plain-text summary to paste in a support request: no tenant, address or secret. */
    #[Computed]
    public function report(): string
    {
        $titles = $this->titles;
        $lines = [config('app.name').' '.app_version().' · '.now()->toIso8601String(), ''];
        foreach ($this->checks as $group => $checks) {
            $lines[] = '## '.$titles[$group];
            foreach ($checks as $check) {
                $lines[] = sprintf('[%s] %s: %s', strtoupper($check['status']), $check['label'], $check['value'] ?? '—');
            }
            $lines[] = '';
        }
        $lines[] = '## '.__('Agenda sync');
        foreach ($this->rooms as $row) {
            $lines[] = sprintf('[%s] %s: %s', strtoupper($row['status']), $row['room']->name, $row['synced_at']?->diffForHumans() ?? __('Not today'));
        }

        return implode("\n", $lines);
    }

    #[Computed]
    public function titles(): array
    {
        return [
            'microsoft' => 'Microsoft 365',
            'background' => __('Background tasks'),
            'screens' => __('Screens'),
            'server' => __('Server'),
        ];
    }
}; ?>

@php
    $icons = ['microsoft' => 'cloud', 'background' => 'cpu-chip', 'screens' => 'tv', 'server' => 'server-stack'];
    $badge = [
        'ok' => ['check-circle', 'text-available'],
        'warning' => ['exclamation-triangle', 'text-amber-500'],
        'error' => ['x-circle', 'text-busy'],
        'info' => ['information-circle', 'text-zinc-400 dark:text-zinc-500'],
    ];
    $counts = $this->counts;
    $titles = $this->titles;
@endphp

<div wire:poll.60s.visible>
    <x-admin.page-header icon="lifebuoy" :title="__('Diagnostics')" :description="__('State of the connection to Microsoft 365, of the background tasks and of the server.')">
        <x-slot:actions>
            <flux:button icon="clipboard-document" :data-report="$this->report" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.report); copied = true; setTimeout(() => copied = false, 2000)">
                <span x-show="! copied">{{ __('Copy the report') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </flux:button>
            <flux:button variant="primary" icon="play" wire:click="runTests" wire:loading.attr="disabled" wire:target="runTests">
                <span wire:loading.remove wire:target="runTests">{{ __('Test Microsoft 365') }}</span>
                <span wire:loading wire:target="runTests">{{ __('Testing…') }}</span>
            </flux:button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Overall state --}}
    <div @class([
        'mb-8 flex items-center gap-4 rounded-2xl border px-5 py-4',
        'border-available/25 bg-available/[0.06]' => $counts['error'] === 0 && $counts['warning'] === 0,
        'border-amber-400/40 bg-amber-50 dark:border-amber-400/25 dark:bg-amber-400/[0.06]' => $counts['error'] === 0 && $counts['warning'] > 0,
        'border-busy/30 bg-busy/[0.06]' => $counts['error'] > 0,
    ])>
        @if ($counts['error'] > 0)
            <flux:icon.x-circle class="size-8 shrink-0 text-busy" />
        @elseif ($counts['warning'] > 0)
            <flux:icon.exclamation-triangle class="size-8 shrink-0 text-amber-500" />
        @else
            <flux:icon.check-circle class="size-8 shrink-0 text-available" />
        @endif
        <div class="min-w-0">
            <p class="font-semibold text-zinc-900 dark:text-white">
                @if ($counts['error'] > 0)
                    {{ trans_choice(':count problem to fix|:count problems to fix', $counts['error']) }}@if ($counts['warning'] > 0), {{ trans_choice(':count point to check|:count points to check', $counts['warning']) }}@endif
                @elseif ($counts['warning'] > 0)
                    {{ trans_choice(':count point to check|:count points to check', $counts['warning']) }}
                @else
                    {{ __('Everything works') }}
                @endif
            </p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                @if ($testedAt)
                    {{ __('Microsoft 365 tested :ago.', ['ago' => \Carbon\CarbonImmutable::parse($testedAt)->diffForHumans()]) }}
                @else
                    {{ __('Microsoft 365 has not been tested on this visit.') }}
                @endif
                {{ __('The page refreshes every minute.') }}
            </p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($this->checks as $group => $checks)
            <x-admin.card :icon="$icons[$group]" :title="$titles[$group]" :padded="false" wire:key="group-{{ $group }}">
                <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach ($checks as $check)
                        <li class="flex items-start gap-3 px-5 py-3">
                            <flux:icon :name="$badge[$check['status']][0]" variant="mini" class="mt-0.5 size-5 shrink-0 {{ $badge[$check['status']][1] }}" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-4">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $check['label'] }}</span>
                                    @if ($check['value'] !== null)
                                        <span class="truncate text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $check['value'] }}</span>
                                    @endif
                                </div>
                                @if ($check['hint'])
                                    <p class="mt-0.5 text-xs break-words text-zinc-500">{!! preg_replace('/`([^`]+)`/', '<code class="rounded bg-zinc-100 px-1 py-px font-mono text-[11px] dark:bg-white/10">$1</code>', e($check['hint'])) !!}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endforeach

        <x-admin.card icon="arrow-path" :title="__('Agenda sync')" :description="__('Agendas are read from Microsoft 365 while a screen or an administrator looks at them.')" :padded="false" class="lg:col-span-2">
            @if (count($this->rooms) === 0)
                <p class="px-5 py-8 text-center text-sm text-zinc-500">{{ __('No rooms yet') }}</p>
            @else
                <ul class="grid divide-zinc-100 sm:grid-cols-2 sm:divide-x xl:grid-cols-3 dark:divide-white/[0.06]">
                    @foreach ($this->rooms as $row)
                        <li wire:key="sync-{{ $row['room']->id }}" class="flex items-center gap-3 border-b border-zinc-100 px-5 py-3 dark:border-white/[0.06]">
                            <flux:icon :name="$badge[$row['status']][0]" variant="mini" class="size-5 shrink-0 {{ $badge[$row['status']][1] }}" />
                            <a href="{{ route('admin.rooms.show', $row['room']) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 hover:underline dark:text-white">{{ $row['room']->name }}</a>
                            <span class="shrink-0 text-xs text-zinc-500" @if ($row['synced_at']) title="{{ fmt_time($row['synced_at']) }}" @endif>
                                {{ $row['synced_at']?->diffForHumans() ?? __('Not today') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>
    </div>
</div>
