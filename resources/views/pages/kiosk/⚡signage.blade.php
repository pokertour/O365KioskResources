<?php

use App\Models\Announcement;
use App\Models\AppSetting;
use App\Models\BrandingSetting;
use App\Models\Signboard;
use App\Services\RoomSchedule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::kiosk')]
#[Title('Wayfinding')]
class extends Component {
    #[Locked]
    public int $signboardId = 0;

    /** Hash of the token used to open the display: checked on every poll so revoked links stop working. */
    #[Locked]
    public string $tokenHash = '';

    #[Locked]
    public string $version = '';

    public function mount(Signboard $signboard, string $token): void
    {
        abort_unless($signboard->tokenMatches($token), 404);

        $this->signboardId = $signboard->id;
        $this->tokenHash = $signboard->token_hash;
        $this->version = $this->currentVersion($signboard);
        $signboard->markSeen();
    }

    public function hydrate(): void
    {
        $signboard = $this->signboard;
        if (! $signboard || ! hash_equals($signboard->token_hash, $this->tokenHash)) {
            // Display removed or its link regenerated: reloading lands on the 404 page.
            $this->js('window.location.reload()');
            $this->skipRender();

            return;
        }

        $signboard->markSeen();

        if ($this->currentVersion($signboard) !== $this->version) {
            $this->js('window.location.reload()');
        }
    }

    private function currentVersion(Signboard $signboard): string
    {
        return BrandingSetting::current()->version().'-'.AppSetting::current()->updated_at?->getTimestamp()
            .'-'.$signboard->updated_at?->getTimestamp();
    }

    #[Computed]
    public function signboard(): ?Signboard
    {
        return Signboard::query()->find($this->signboardId);
    }

    /**
     * Each room with its status and the meeting to show (current one, else the next one today).
     *
     * @return list<array{room: \App\Models\Room, direction: ?string, state: ?string, event: ?array, until: mixed, organizer: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $schedule = app(RoomSchedule::class);

        return $this->signboard->rooms->map(function ($room) use ($schedule) {
            $day = $schedule->today($room);
            $status = $schedule->status($day['events']);
            $event = $status['current'] ?? $status['next'];
            $unavailable = $day['error'] !== null && $day['events'] === [];

            return [
                'room' => $room,
                'direction' => $room->pivot->direction,
                'state' => $unavailable ? null : $status['state'],
                'event' => $event ? ['subject' => $schedule->displaySubject($room, $event)] + $event : null,
                'until' => $status['until'],
                'organizer' => $event && ! $event['is_private'] && $room->show_subjects && AppSetting::forRoom($room)->show_organizer,
            ];
        })->all();
    }

    #[Computed]
    public function announcement(): ?Announcement
    {
        // Only announcements for every room: a lobby is not in front of one room.
        return Announcement::query()->active()->latest()->get()->first(fn (Announcement $a) => empty($a->room_ids));
    }
}; ?>

@php
    $settings = \App\Models\AppSetting::current();
    $now = app(\App\Services\RoomSchedule::class)->now();
    $clock = [
        'locale' => app()->getLocale() === 'fr' ? 'fr-FR' : 'en-US',
        'hour12' => $settings->uses12h(),
        'timeZone' => $settings->timezone(),
    ];
    $rows = $this->rows;
    $perPage = max(1, $this->signboard->rooms_per_page);
    $pages = max(1, (int) ceil(count($rows) / $perPage));
    $labels = ['busy' => __('Busy'), 'soon' => __('Starting soon'), 'free' => __('Available')];
@endphp

<div
    class="relative flex h-full flex-col overflow-hidden"
    wire:poll.{{ $settings->refreshSeconds() }}s.keep-alive
    x-data="{
        page: 0,
        pages: {{ $pages }},
        init() { setInterval(() => { if (this.pages > 1) this.page = (this.page + 1) % this.pages }, {{ \App\Models\Signboard::PAGE_SECONDS * 1000 }}) },
    }"
>
    {{-- Soft brand light behind the content --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -top-1/3 h-2/3 bg-[radial-gradient(50%_50%_at_30%_50%,color-mix(in_oklab,var(--color-brand)_12%,transparent),transparent)]"></div>

    <header class="relative flex items-center justify-between gap-6 px-[5vmin] pt-[4vmin]">
        <div class="flex min-w-0 items-center gap-4">
            <x-brand-mark />
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold tracking-tight">{{ $branding->displayName() }}</p>
                <p class="truncate text-base text-zinc-500 dark:text-zinc-400">{{ $this->signboard->name }}</p>
            </div>
        </div>
        <x-kiosk.clock :options="$clock" :now="$now" />
    </header>

    <main class="relative flex min-h-0 flex-1 flex-col justify-center px-[5vmin] py-[3vmin]">
        @if (count($rows) === 0)
            <p class="text-center text-2xl text-zinc-500">{{ __('No rooms on this display yet.') }}</p>
        @endif

        @foreach (array_chunk($rows, $perPage) as $index => $chunk)
            <ul
                wire:key="page-{{ $index }}"
                class="flex flex-col gap-[3.2vmin]"
                @if ($pages > 1)
                    x-show="page === {{ $index }}"
                    x-transition:enter="transition duration-700 ease-out"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    @if ($index > 0) x-cloak @endif
                @endif
            >
                @foreach ($chunk as $row)
                    @php
                        $state = $row['state'];
                        $event = $row['event'];
                        $tone = match ($state) {
                            'busy' => ['bg-busy text-busy-foreground', 'ring-busy/60', 'bg-busy/[0.07]', 'text-busy'],
                            'soon' => ['bg-soon text-soon-foreground', 'ring-soon/60', 'bg-soon/[0.07]', 'text-soon'],
                            'free' => ['bg-available text-available-foreground', 'ring-available/60', 'bg-available/[0.07]', 'text-available'],
                            default => ['bg-zinc-500 text-white', 'ring-zinc-500/40', 'bg-zinc-500/[0.05]', 'text-zinc-500'],
                        };
                    @endphp
                    <li wire:key="row-{{ $row['room']->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-[4vmin]">
                        <div class="min-w-0">
                            <div class="flex items-baseline gap-4">
                                <h2 class="truncate text-[2.1rem] leading-tight font-medium tracking-tight">{{ $row['room']->name }}</h2>
                                @if ($state)
                                    <span class="inline-flex shrink-0 items-center gap-2 text-lg font-semibold {{ $tone[3] }}">
                                        <span class="size-2.5 rounded-full bg-current"></span>
                                        {{ $labels[$state] }}
                                        @if ($state === 'busy' && $row['until'])
                                            <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ __('Free at :time', ['time' => fmt_time($row['until'])]) }}</span>
                                        @endif
                                    </span>
                                @endif
                            </div>

                            <div class="mt-2.5 flex max-w-4xl overflow-hidden rounded-lg ring-2 ring-inset {{ $tone[1] }} {{ $tone[2] }}">
                                @if ($event)
                                    <div class="flex w-[6.5rem] shrink-0 flex-col justify-center px-4 py-2.5 text-xl leading-snug font-medium tabular-nums {{ $tone[0] }}">
                                        @if ($event['is_all_day'])
                                            <span>{{ __('All day') }}</span>
                                        @else
                                            <span>{{ fmt_time($event['start']) }}</span>
                                            <span class="opacity-80">{{ fmt_time($event['end']) }}</span>
                                        @endif
                                    </div>
                                    <div class="flex min-w-0 flex-col justify-center px-5 py-2.5">
                                        <p class="truncate text-xl font-medium">
                                            @if ($state !== 'busy')<span class="me-2 text-base font-semibold tracking-wide uppercase opacity-60">{{ __('Next') }}</span>@endif{{ $event['subject'] }}
                                        </p>
                                        @if ($row['organizer'] && $event['organizer'])
                                            <p class="truncate text-lg text-zinc-500 dark:text-zinc-400">{{ $event['organizer'] }}</p>
                                        @endif
                                    </div>
                                @elseif ($state === 'free')
                                    <div class="flex w-[6.5rem] shrink-0 items-center justify-center py-4 {{ $tone[0] }}">
                                        <flux:icon.check class="size-8" />
                                    </div>
                                    <p class="flex items-center px-5 text-xl font-medium">{{ __('Free all day') }}</p>
                                @else
                                    <p class="flex items-center px-5 py-4 text-xl text-zinc-500">{{ __('Agenda unavailable') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex w-[7rem] justify-center">
                            @if ($row['direction'])
                                <x-kiosk.direction :direction="$row['direction']" class="size-[5.5rem] text-zinc-900 dark:text-white" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </main>

    <footer class="relative flex min-h-[4vmin] items-center justify-between gap-6 px-[5vmin] pb-[3.5vmin]">
        @if ($announcement = $this->announcement)
            <div @class([
                'flex min-w-0 items-center gap-3 rounded-xl px-4 py-2.5 text-lg font-medium',
                'bg-sky-500/15 text-sky-800 ring-1 ring-sky-500/30 dark:text-sky-200' => $announcement->level === 'info',
                'bg-amber-500/15 text-amber-800 ring-1 ring-amber-500/30 dark:text-amber-200' => $announcement->level === 'warning',
                'bg-red-500/15 text-red-800 ring-1 ring-red-500/30 dark:text-red-200' => $announcement->level === 'danger',
            ])>
                <flux:icon :name="$announcement->level === 'info' ? 'megaphone' : 'exclamation-triangle'" class="size-6 shrink-0" />
                <span class="truncate">{{ $announcement->message }}</span>
            </div>
        @else
            <span></span>
        @endif

        @if ($pages > 1)
            <div class="flex shrink-0 items-center gap-2" aria-hidden="true">
                @foreach (range(0, $pages - 1) as $i)
                    <span class="h-2 rounded-full bg-zinc-400/40 transition-all duration-500" x-bind:class="page === {{ $i }} ? 'w-8 !bg-zinc-900 dark:!bg-white' : 'w-2'"></span>
                @endforeach
            </div>
        @endif
    </footer>
</div>
