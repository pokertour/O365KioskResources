<?php

use App\Models\ActivityLog;
use App\Models\IssueReport;
use App\Models\Room;
use App\Services\MicrosoftGraphService;
use App\Services\RoomSchedule;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rooms')] class extends Component {
    public string $search = '';

    public string $name = '';

    public string $o365_resource_email = '';

    public string $location = '';

    public ?int $capacity = null;

    /** @var list<array{name: string, email: string, capacity: ?int, location: ?string}> */
    public array $importable = [];

    /** @var list<string> */
    public array $importSelection = [];

    public ?string $importError = null;

    #[Computed]
    public function rooms()
    {
        return Room::query()
            ->with('screens')
            ->withCount(['issueReports as open_issues_count' => fn ($q) => $q->whereNull('resolved_at')])
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('location', 'like', $term)
                    ->orWhere('o365_resource_email', 'like', $term));
            })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function graphConfigured(): bool
    {
        return app(MicrosoftGraphService::class)->isConfigured();
    }

    /**
     * Live status of every room (agendas are cached ~20 s, shared with the screens).
     *
     * @return array<int, array{state: string, current: ?array, next: ?array, error: ?string}>
     */
    #[Computed]
    public function live(): array
    {
        if (! $this->graphConfigured) {
            return [];
        }

        $schedule = app(RoomSchedule::class);
        $out = [];
        foreach ($this->rooms as $room) {
            $day = $schedule->today($room);
            $status = $schedule->status($day['events']);
            $out[$room->id] = [
                'state' => $status['state'],
                'current' => $status['current'] ? ['subject' => $schedule->displaySubject($room, $status['current'])] + $status['current'] : null,
                'next' => $status['next'] ? ['subject' => $schedule->displaySubject($room, $status['next'])] + $status['next'] : null,
                'until' => $status['until'],
                'error' => $day['error'],
            ];
        }

        return $out;
    }

    #[Computed]
    public function summary(): array
    {
        $states = collect($this->live)->pluck('state');
        $rooms = $this->rooms;

        return [
            'free' => $states->filter(fn ($s) => $s !== 'busy')->count(),
            'busy' => $states->filter(fn ($s) => $s === 'busy')->count(),
            'offline' => $rooms->flatMap->screens->filter(fn ($s) => $s->seen_at !== null && ! $s->isOnline())->count(),
            'issues' => IssueReport::query()->open()->count(),
        ];
    }

    public function createRoom(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'o365_resource_email' => ['required', 'email:rfc', 'max:255', Rule::unique('rooms', 'o365_resource_email')],
            'location' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], [], [
            'name' => __('name'),
            'o365_resource_email' => __('room address'),
            'location' => __('location'),
            'capacity' => __('capacity'),
        ]);

        $room = Room::query()->create([
            ...$validated,
            'o365_resource_email' => strtolower($validated['o365_resource_email']),
            'location' => trim((string) $validated['location']) ?: null,
        ]);
        ActivityLog::record('room.created', $room, $room->name);

        Flux::toast(variant: 'success', heading: __('Room created'), text: __('The screen link is ready.'));
        $this->redirect(route('admin.rooms.show', $room), navigate: true);
    }

    public function openImport(): void
    {
        $this->importable = [];
        $this->importSelection = [];
        $this->importError = null;

        try {
            $existing = Room::query()->pluck('o365_resource_email')->map(fn ($e) => strtolower($e))->all();
            $this->importable = array_values(array_filter(
                app(MicrosoftGraphService::class)->listRooms(),
                fn (array $r) => ! in_array($r['email'], $existing, true),
            ));
        } catch (\Throwable $e) {
            Log::warning('Room import failed', ['exception' => $e->getMessage()]);
            $this->importError = str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), 'Authorization_RequestDenied')
                ? __('Microsoft 365 denied access to the room list. Add the Place.Read.All application permission and grant admin consent (Settings › Microsoft 365 shows what is granted). If you just did it, wait a few minutes and try again.')
                : $e->getMessage();
        }

        $this->modal('import-rooms')->show();
    }

    public function importRooms(): void
    {
        $byEmail = collect($this->importable)->keyBy('email');
        $count = 0;

        foreach (array_unique($this->importSelection) as $email) {
            $data = $byEmail->get($email);
            if (! $data || Room::query()->where('o365_resource_email', $email)->exists()) {
                continue;
            }
            $room = Room::query()->create([
                'name' => Str::limit($data['name'], 120, ''),
                'o365_resource_email' => $email,
                'location' => $data['location'],
                'capacity' => $data['capacity'],
            ]);
            ActivityLog::record('room.created', $room, $room->name, ['source' => 'import']);
            $count++;
        }

        $this->modal('import-rooms')->close();
        $this->importable = [];
        $this->importSelection = [];
        unset($this->rooms, $this->live, $this->summary);
        Flux::toast(variant: 'success', text: trans_choice(':count room imported.|:count rooms imported.', $count));
    }
}; ?>

<div wire:poll.30s.visible>
    <x-admin.page-header :title="__('Rooms')" :description="__('Live status of every room and of its screen.')">
        <x-slot:actions>
            @if ($this->graphConfigured)
                <flux:button icon="arrow-down-tray" wire:click="openImport" wire:loading.attr="disabled" wire:target="openImport">{{ __('Import from Microsoft 365') }}</flux:button>
            @endif
            <flux:modal.trigger name="create-room">
                <flux:button variant="primary" icon="plus">{{ __('New room') }}</flux:button>
            </flux:modal.trigger>
        </x-slot:actions>
    </x-admin.page-header>

    @unless ($this->graphConfigured)
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6">
            <flux:callout.heading>{{ __('Microsoft 365 is not connected yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Screens will not show any agenda until the connection is configured.') }}
                <flux:callout.link :href="route('admin.microsoft')" wire:navigate>{{ __('Configure the connection') }}</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    @endunless

    @if ($this->rooms->isNotEmpty() || $search !== '')
        @php
            $summary = $this->summary;
        @endphp
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['free', __('Available'), 'bg-available', $summary['free']],
                ['busy', __('Busy'), 'bg-busy', $summary['busy']],
                ['offline', __('Screens offline'), 'bg-zinc-400', $summary['offline']],
                ['issues', __('Open issues'), 'bg-amber-500', $summary['issues']],
            ] as [$key, $label, $dot, $value])
                <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-white/[0.08] dark:bg-zinc-900">
                    <div class="flex items-center gap-2 text-xs font-medium text-zinc-500"><span class="size-2 rounded-full {{ $dot }}"></span>{{ $label }}</div>
                    <div @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-zinc-900 dark:text-white' => ! (in_array($key, ['offline', 'issues']) && $value > 0), 'text-amber-600 dark:text-amber-400' => in_array($key, ['offline', 'issues']) && $value > 0])>{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="mb-5 max-w-sm">
            <flux:input wire:model.live.debounce.250ms="search" icon="magnifying-glass" :placeholder="__('Search a room…')" clearable />
        </div>
    @endif

    @if ($this->rooms->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-white/10">
            <div class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 dark:bg-white/5">
                <flux:icon.building-office-2 class="size-6 text-zinc-400" />
            </div>
            @if ($search !== '')
                <p class="mt-4 font-medium text-zinc-900 dark:text-white">{{ __('No results') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('No room matches “:search”.', ['search' => $search]) }}</p>
            @else
                <p class="mt-4 font-medium text-zinc-900 dark:text-white">{{ __('No rooms yet') }}</p>
                <p class="mt-1 max-w-sm text-sm text-zinc-500">{{ __('Add your first room to generate the link of its display screen.') }}</p>
                <flux:modal.trigger name="create-room">
                    <flux:button variant="primary" icon="plus" class="mt-5">{{ __('New room') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->rooms as $room)
                @php
                    $live = $this->live[$room->id] ?? null;
                    $state = $live['state'] ?? null;
                    $seen = $room->screens->whereNotNull('seen_at');
                    $onlineCount = $seen->filter(fn ($s) => $s->isOnline())->count();
                    $kioskUrl = $room->screens->first()?->kioskUrl();
                @endphp
                <article wire:key="room-{{ $room->id }}" class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:border-zinc-300 hover:shadow-sm dark:border-white/[0.08] dark:bg-zinc-900 dark:hover:border-white/15">
                    <div @class([
                        'h-1',
                        'bg-busy' => $state === 'busy',
                        'bg-soon' => $state === 'soon',
                        'bg-available' => $state === 'free',
                        'bg-zinc-200 dark:bg-white/10' => $state === null,
                    ])></div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.rooms.show', $room) }}" wire:navigate class="block truncate font-semibold text-zinc-900 after:absolute after:inset-0 dark:text-white">
                                    {{ $room->name }}
                                </a>
                                <p class="truncate text-sm text-zinc-500">{{ $room->location ?: $room->o365_resource_email }}</p>
                            </div>
                            @if ($state)
                                <span @class([
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    'bg-busy/10 text-busy' => $state === 'busy',
                                    'bg-soon/10 text-soon' => $state === 'soon',
                                    'bg-available/10 text-available' => $state === 'free',
                                ])>
                                    <span class="size-1.5 rounded-full bg-current"></span>
                                    {{ ['busy' => __('Busy'), 'soon' => __('Starting soon'), 'free' => __('Available')][$state] }}
                                </span>
                            @endif
                        </div>

                        <div class="mt-4 min-h-10 text-sm">
                            @if ($live && $live['current'])
                                <p class="truncate font-medium text-zinc-900 dark:text-white">{{ $live['current']['subject'] }}</p>
                                <p class="text-zinc-500">{{ __('Free at :time', ['time' => fmt_time($live['until'])]) }}</p>
                            @elseif ($live && $live['next'])
                                <p class="truncate text-zinc-700 dark:text-zinc-300">{{ __('Next') }} · {{ fmt_time($live['next']['start']) }} — {{ $live['next']['subject'] }}</p>
                                <p class="text-zinc-500">{{ __('Free until :time', ['time' => fmt_time($live['next']['start'])]) }}</p>
                            @elseif ($live && $live['error'] === null)
                                <p class="text-zinc-500">{{ __('Free all day') }}</p>
                            @elseif ($live)
                                <p class="text-amber-600 dark:text-amber-400">{{ __('Unable to read the calendar') }}</p>
                            @endif
                        </div>

                        <div class="relative z-10 mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-zinc-100 pt-3 text-xs dark:border-white/[0.06]">
                            @if ($seen->isEmpty())
                                <span class="inline-flex items-center gap-1.5 text-zinc-400"><flux:icon.tv variant="micro" />{{ __('No screen yet') }}</span>
                            @elseif ($onlineCount === $seen->count())
                                <span class="inline-flex items-center gap-1.5 text-available"><span class="size-1.5 rounded-full bg-current"></span>{{ trans_choice(':online/:total screen online|:online/:total screens online', $seen->count(), ['online' => $onlineCount, 'total' => $seen->count()]) }}</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 font-medium text-amber-600 dark:text-amber-400">
                                    <span class="size-1.5 rounded-full bg-current"></span>{{ trans_choice(':online/:total screen online|:online/:total screens online', $seen->count(), ['online' => $onlineCount, 'total' => $seen->count()]) }}
                                </span>
                            @endif
                            @if ($room->open_issues_count > 0)
                                <span class="inline-flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" />{{ trans_choice(':count issue|:count issues', $room->open_issues_count) }}</span>
                            @endif
                            @if ($kioskUrl)
                                <a href="{{ $kioskUrl }}" target="_blank" rel="noopener noreferrer" class="ms-auto inline-flex items-center gap-1 text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                                    <flux:icon.arrow-top-right-on-square variant="micro" />{{ __('Open screen') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-room" variant="flyout" class="w-full max-w-md">
        <form wire:submit="createRoom" class="flex h-full flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('New room') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The secure screen link is generated automatically.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Horizon room')" required autofocus />
            <flux:input
                wire:model="o365_resource_email"
                type="email"
                :label="__('Microsoft 365 room address')"
                :description="__('SMTP address of the resource mailbox.')"
                placeholder="horizon@contoso.com"
                required
            />
            <div class="grid grid-cols-[1fr_8rem] gap-4">
                <flux:input wire:model="location" :label="__('Location')" :placeholder="__('2nd floor')" />
                <flux:input wire:model="capacity" type="number" min="1" :label="__('Capacity')" placeholder="8" />
            </div>

            <div class="mt-auto flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create room') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="import-rooms" variant="flyout" class="w-full max-w-lg">
        <form wire:submit="importRooms" class="flex h-full flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Import from Microsoft 365') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Room mailboxes of your tenant that are not added yet.') }}</flux:text>
            </div>

            @if ($importError)
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $importError }}</flux:callout.text>
                </flux:callout>
            @elseif (count($importable) === 0)
                <p class="text-sm text-zinc-500">{{ __('Every room of the tenant is already added.') }}</p>
            @else
                <div class="flex items-center justify-between text-sm">
                    <span class="text-zinc-500">{{ trans_choice(':count room available|:count rooms available', count($importable)) }}</span>
                    <flux:button size="sm" variant="ghost" type="button" :data-emails="json_encode(array_column($importable, 'email'))" x-on:click="$wire.importSelection = JSON.parse($el.dataset.emails)">{{ __('Select all') }}</flux:button>
                </div>
                <div class="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1">
                    @foreach ($importable as $candidate)
                        <label wire:key="imp-{{ md5($candidate['email']) }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2.5 hover:bg-zinc-50 dark:border-white/10 dark:hover:bg-white/5">
                            <input type="checkbox" wire:model="importSelection" value="{{ $candidate['email'] }}" class="size-4 rounded accent-(--color-brand)" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $candidate['name'] }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $candidate['email'] }}@if ($candidate['location']) · {{ $candidate['location'] }}@endif @if ($candidate['capacity']) · {{ trans_choice(':count seat|:count seats', $candidate['capacity']) }}@endif</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            <div class="mt-auto flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                @if (count($importable) > 0)
                    <flux:button variant="primary" type="submit">{{ __('Import selection') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</div>
