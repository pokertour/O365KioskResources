<?php

use App\Models\ActivityLog;
use App\Models\Room;
use App\Models\Signboard;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wayfinding displays')] class extends Component {
    /** Display being edited (null = new one). */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public int $rooms_per_page = 5;

    /** Every room, in display order: [{id, name, location, enabled, direction}]. */
    public array $rooms = [];

    #[Locked]
    public ?int $deletingId = null;

    #[Computed]
    public function signboards()
    {
        return Signboard::query()->with('rooms')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = '';
        $this->rooms_per_page = 5;
        $this->rooms = Room::query()->orderBy('name')->get()
            ->map(fn (Room $room) => ['id' => $room->id, 'name' => $room->name, 'location' => $room->location, 'enabled' => false, 'direction' => ''])
            ->all();
        $this->modal('signboard-form')->show();
    }

    public function edit(int $id): void
    {
        $signboard = Signboard::query()->with('rooms')->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $signboard->id;
        $this->name = $signboard->name;
        $this->rooms_per_page = $signboard->rooms_per_page;

        // Rooms of the display first, in their order, then the others alphabetically.
        $chosen = $signboard->rooms->map(fn (Room $room) => ['id' => $room->id, 'name' => $room->name, 'location' => $room->location, 'enabled' => true, 'direction' => (string) $room->pivot->direction]);
        $others = Room::query()->whereNotIn('id', $chosen->pluck('id'))->orderBy('name')->get()
            ->map(fn (Room $room) => ['id' => $room->id, 'name' => $room->name, 'location' => $room->location, 'enabled' => false, 'direction' => '']);
        $this->rooms = $chosen->concat($others)->values()->all();
        $this->modal('signboard-form')->show();
    }

    public function move(int $index, int $step): void
    {
        $target = $index + $step;
        if (! isset($this->rooms[$index], $this->rooms[$target])) {
            return;
        }
        [$this->rooms[$index], $this->rooms[$target]] = [$this->rooms[$target], $this->rooms[$index]];
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'rooms_per_page' => ['required', 'integer', Rule::in(Signboard::ROOMS_PER_PAGE)],
            'rooms' => ['array'],
            'rooms.*.id' => ['required', 'integer', 'exists:rooms,id'],
            'rooms.*.enabled' => ['boolean'],
            'rooms.*.direction' => ['nullable', 'string', Rule::in(['', ...Signboard::DIRECTIONS])],
        ], [], ['name' => __('name')]);

        $signboard = $this->editingId
            ? Signboard::query()->findOrFail($this->editingId)
            : new Signboard;
        $signboard->fill(['name' => trim($this->name), 'rooms_per_page' => $this->rooms_per_page])->save();

        $signboard->syncRooms(collect($this->rooms)
            ->filter(fn (array $room) => $room['enabled'])
            ->mapWithKeys(fn (array $room) => [(int) $room['id'] => $room['direction'] ?: null])
            ->all());

        ActivityLog::record($this->editingId ? 'signage.updated' : 'signage.created', null, $signboard->name);
        unset($this->signboards);
        $this->modal('signboard-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Display updated.') : __('Display created: open its link on the screen.'));
    }

    public function regenerate(int $id): void
    {
        $signboard = Signboard::query()->findOrFail($id);
        $signboard->rotateToken();
        ActivityLog::record('signage.link_rotated', null, $signboard->name);
        unset($this->signboards);
        Flux::toast(variant: 'success', text: __('New link generated. The display using the old one has been disconnected.'));
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->modal('signboard-delete')->show();
    }

    public function delete(): void
    {
        $signboard = Signboard::query()->find($this->deletingId);
        if ($signboard) {
            ActivityLog::record('signage.deleted', null, $signboard->name);
            $signboard->delete();
        }
        $this->deletingId = null;
        unset($this->signboards);
        $this->modal('signboard-delete')->close();
        Flux::toast(variant: 'success', text: __('Display deleted.'));
    }
}; ?>

@php
    $directionLabels = [
        'left' => __('Left'), 'right' => __('Right'), 'up' => __('Straight ahead'), 'down' => __('Behind you'),
        'up-left' => __('Ahead on the left'), 'up-right' => __('Ahead on the right'),
        'down-left' => __('Behind on the left'), 'down-right' => __('Behind on the right'),
        'stairs-up' => __('Upstairs'), 'stairs-down' => __('Downstairs'), 'elevator' => __('Elevator'),
    ];
@endphp

<div wire:poll.30s.visible>
    <x-admin.page-header icon="map" :title="__('Wayfinding displays')" :description="__('Optional screens for a lobby or a corridor: the status of several rooms, and the way to each one.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New display') }}</flux:button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($this->signboards->isEmpty())
        <div class="relative overflow-hidden rounded-2xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-white/10">
            {{-- Miniature of a display --}}
            <div aria-hidden="true" class="mx-auto w-full max-w-sm rounded-xl bg-zinc-950 p-4 text-left shadow-xl ring-1 ring-black/10">
                @foreach ([['bg-busy', 'right'], ['bg-available', 'left'], ['bg-soon', 'stairs-up']] as [$color, $dir])
                    <div class="flex items-center gap-3 py-1.5">
                        <div class="flex-1">
                            <div class="h-2 w-16 rounded-full bg-white/70"></div>
                            <div class="mt-1.5 flex h-5 overflow-hidden rounded ring-1 ring-white/15">
                                <div class="w-7 {{ $color }}"></div>
                                <div class="flex flex-1 items-center px-2"><div class="h-1.5 w-20 rounded-full bg-white/30"></div></div>
                            </div>
                        </div>
                        <x-kiosk.direction :direction="$dir" class="size-6 text-white" />
                    </div>
                @endforeach
            </div>
            <p class="mt-6 font-medium text-zinc-900 dark:text-white">{{ __('No wayfinding display yet') }}</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-zinc-500">{{ __('Put a screen at the entrance of a floor: it lists the rooms nearby, whether they are free, and which way to go.') }}</p>
            <flux:button variant="primary" icon="plus" class="mt-5" wire:click="create">{{ __('New display') }}</flux:button>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->signboards as $signboard)
                @php
                    $url = $signboard->url();
                @endphp
                <article wire:key="sb-{{ $signboard->id }}" class="flex flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-xs shadow-zinc-950/[0.03] dark:border-white/[0.07] dark:bg-white/[0.025]">
                    <div class="flex items-start justify-between gap-3 p-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/20 ring-inset dark:text-[color-mix(in_oklab,var(--color-brand)_55%,white)]">
                                <flux:icon.map class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <h2 class="truncate font-semibold text-zinc-900 dark:text-white">{{ $signboard->name }}</h2>
                                <p class="text-xs text-zinc-500">{{ trans_choice(':count room|:count rooms', $signboard->rooms->count()) }}</p>
                            </div>
                        </div>
                        @if ($signboard->seen_at === null)
                            <span class="inline-flex shrink-0 items-center gap-1.5 text-xs text-zinc-400"><span class="size-1.5 rounded-full bg-current"></span>{{ __('Never connected') }}</span>
                        @elseif ($signboard->isOnline())
                            <span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-available"><span class="size-1.5 rounded-full bg-current"></span>{{ __('Online') }}</span>
                        @else
                            <span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400"><span class="size-1.5 rounded-full bg-current"></span>{{ __('Offline :since', ['since' => $signboard->seen_at->diffForHumans(short: true)]) }}</span>
                        @endif
                    </div>

                    {{-- What the display shows --}}
                    <ul class="mx-5 mb-5 flex-1 divide-y divide-zinc-100 rounded-xl bg-zinc-50 px-3 dark:divide-white/[0.06] dark:bg-white/[0.03]">
                        @forelse ($signboard->rooms as $room)
                            <li class="flex items-center gap-3 py-2 text-sm">
                                <span class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-300">{{ $room->name }}</span>
                                @if ($room->pivot->direction)
                                    <span class="flex items-center gap-1.5 text-xs text-zinc-500">
                                        {{ $directionLabels[$room->pivot->direction] ?? '' }}
                                        <x-kiosk.direction :direction="$room->pivot->direction" class="size-4 text-zinc-700 dark:text-zinc-200" />
                                    </span>
                                @endif
                            </li>
                        @empty
                            <li class="py-3 text-center text-sm text-zinc-500">{{ __('No rooms selected.') }}</li>
                        @endforelse
                    </ul>

                    <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 px-5 py-3 dark:border-white/[0.06]">
                        <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $signboard->id }})">{{ __('Edit') }}</flux:button>
                        @if ($url)
                            <flux:button size="sm" variant="ghost" icon="link" :data-url="$url" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.url); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-show="! copied">{{ __('Copy link') }}</span>
                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$url" target="_blank" rel="noopener noreferrer">{{ __('Open') }}</flux:button>
                        @endif
                        <flux:spacer />
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                            <flux:menu>
                                <flux:menu.item icon="arrow-path" wire:click="regenerate({{ $signboard->id }})" wire:confirm="{{ __('The display using the current link will be disconnected. Continue?') }}">{{ __('Regenerate the link') }}</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $signboard->id }})">{{ __('Delete') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{-- Create / edit --}}
    <flux:modal name="signboard-form" variant="flyout" class="w-full max-w-lg">
        <form wire:submit="save" class="flex h-full flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit the display') : __('New display') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Choose the rooms, their order and the way to each one from where the screen hangs.') }}</flux:text>
            </div>

            <div class="grid grid-cols-[1fr_9rem] gap-4">
                <flux:input wire:model="name" :label="__('Name')" :placeholder="__('2nd floor lobby')" required />
                <flux:select wire:model="rooms_per_page" :label="__('Rooms per page')">
                    @foreach (\App\Models\Signboard::ROOMS_PER_PAGE as $n)
                        <flux:select.option :value="$n">{{ $n }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <p class="-mt-3 text-xs text-zinc-500">{{ __('When there are more rooms, the display turns the pages every :seconds seconds.', ['seconds' => \App\Models\Signboard::PAGE_SECONDS]) }}</p>

            <div class="min-h-0 flex-1">
                <flux:label>{{ __('Rooms') }}</flux:label>
                <ul class="-mx-1 mt-2 max-h-[55vh] space-y-1.5 overflow-y-auto px-1 py-1">
                    @forelse ($rooms as $i => $room)
                        <li wire:key="form-room-{{ $room['id'] }}" @class([
                            'flex items-center gap-2 rounded-xl border px-3 py-2 transition',
                            'border-brand/40 bg-brand/[0.04] dark:bg-brand/10' => $room['enabled'],
                            'border-zinc-200 dark:border-white/10' => ! $room['enabled'],
                        ])>
                            <input type="checkbox" wire:model.live="rooms.{{ $i }}.enabled" class="size-4 shrink-0 rounded accent-(--color-brand)" aria-label="{{ $room['name'] }}" />
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate text-sm font-medium', 'text-zinc-900 dark:text-white' => $room['enabled'], 'text-zinc-500' => ! $room['enabled']])>{{ $room['name'] }}</p>
                                @if ($room['location'])
                                    <p class="truncate text-xs text-zinc-500">{{ $room['location'] }}</p>
                                @endif
                            </div>
                            @if ($room['enabled'])
                                @if ($room['direction'])
                                    <x-kiosk.direction :direction="$room['direction']" class="size-5 shrink-0 text-zinc-700 dark:text-zinc-200" />
                                @endif
                                <select wire:model.live="rooms.{{ $i }}.direction" class="h-8 w-36 shrink-0 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-700 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-200" aria-label="{{ __('Direction') }}">
                                    <option value="">{{ __('No arrow') }}</option>
                                    @foreach ($directionLabels as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="flex shrink-0 flex-col">
                                    <button type="button" wire:click="move({{ $i }}, -1)" @disabled($i === 0) class="rounded text-zinc-400 hover:text-zinc-900 disabled:opacity-30 dark:hover:text-white" aria-label="{{ __('Move up') }}"><flux:icon.chevron-up variant="micro" /></button>
                                    <button type="button" wire:click="move({{ $i }}, 1)" @disabled($i === count($rooms) - 1) class="rounded text-zinc-400 hover:text-zinc-900 disabled:opacity-30 dark:hover:text-white" aria-label="{{ __('Move down') }}"><flux:icon.chevron-down variant="micro" /></button>
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-xl border border-dashed border-zinc-300 px-4 py-6 text-center text-sm text-zinc-500 dark:border-white/10">{{ __('Add rooms first.') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ $editingId ? __('Save') : __('Create the display') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="signboard-delete" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Delete this display?') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Its link stops working. The rooms are not affected.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
