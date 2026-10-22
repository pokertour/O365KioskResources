<?php

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Room;
use App\Services\RoomSchedule;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Announcements')] class extends Component {
    public ?int $editingId = null;

    public string $message = '';

    public string $level = 'info';

    public bool $allRooms = true;

    /** @var list<string> */
    public array $roomIds = [];

    public string $starts_at = '';

    public string $ends_at = '';

    #[Computed]
    public function announcements()
    {
        return Announcement::query()->latest()->get();
    }

    #[Computed]
    public function rooms()
    {
        return Room::query()->orderBy('name')->get(['id', 'name']);
    }

    private function tz(): string
    {
        return app(RoomSchedule::class)->timezone();
    }

    public function create(): void
    {
        $this->reset('editingId', 'message', 'roomIds');
        $this->level = 'info';
        $this->allRooms = true;
        $this->starts_at = '';
        $this->ends_at = app(RoomSchedule::class)->now()->endOfDay()->format('Y-m-d\TH:i');
        $this->resetValidation();
        $this->modal('announcement')->show();
    }

    public function edit(int $id): void
    {
        $a = Announcement::query()->findOrFail($id);
        $this->editingId = $a->id;
        $this->message = $a->message;
        $this->level = $a->level;
        $this->allRooms = empty($a->room_ids);
        $this->roomIds = array_map('strval', (array) $a->room_ids);
        $this->starts_at = $a->starts_at?->setTimezone($this->tz())->format('Y-m-d\TH:i') ?? '';
        $this->ends_at = $a->ends_at?->setTimezone($this->tz())->format('Y-m-d\TH:i') ?? '';
        $this->resetValidation();
        $this->modal('announcement')->show();
    }

    public function save(): void
    {
        $this->validate([
            'message' => ['required', 'string', 'max:200'],
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'allRooms' => ['boolean'],
            'roomIds' => [$this->allRooms ? 'nullable' : 'required', 'array'],
            'roomIds.*' => ['integer', 'exists:rooms,id'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
        ], [
            'roomIds.required' => __('Choose at least one room.'),
        ], [
            'message' => __('message'),
            'starts_at' => __('start'),
            'ends_at' => __('end'),
        ]);

        $toUtc = fn (string $v) => $v === '' ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $v, $this->tz())->utc();

        $announcement = Announcement::query()->updateOrCreate(['id' => $this->editingId], [
            'message' => trim($this->message),
            'level' => $this->level,
            'room_ids' => $this->allRooms ? null : array_map('intval', $this->roomIds),
            'starts_at' => $toUtc($this->starts_at),
            'ends_at' => $toUtc($this->ends_at),
        ]);
        ActivityLog::record('announcement.saved', null, $announcement->message);

        $this->modal('announcement')->close();
        unset($this->announcements);
        Flux::toast(variant: 'success', text: __('Announcement saved. Screens show it on their next refresh.'));
    }

    public function stop(int $id): void
    {
        Announcement::query()->whereKey($id)->update(['ends_at' => now()]);
        unset($this->announcements);
        Flux::toast(variant: 'success', text: __('Announcement stopped.'));
    }

    public function delete(int $id): void
    {
        $a = Announcement::query()->find($id);
        if ($a) {
            ActivityLog::record('announcement.deleted', null, $a->message);
            $a->delete();
        }
        unset($this->announcements);
    }
}; ?>

@php
    $roomNames = $this->rooms->pluck('name', 'id');
    $tz = app(\App\Services\RoomSchedule::class)->timezone();
@endphp

<div>
    <x-admin.page-header :title="__('Announcements')" :description="__('Messages shown in a banner on the screens: fire drill, closed room, works…')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New announcement') }}</flux:button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card :padded="false">
        @forelse ($this->announcements as $a)
            @php
                $active = $a->isActive();
                $upcoming = $a->starts_at && $a->starts_at->isFuture();
            @endphp
            <div wire:key="ann-{{ $a->id }}" class="flex flex-wrap items-start gap-4 border-b border-zinc-100 px-5 py-4 last:border-0 dark:border-white/[0.06]">
                <span @class([
                    'mt-1 size-2.5 shrink-0 rounded-full',
                    'bg-sky-500' => $a->level === 'info',
                    'bg-amber-500' => $a->level === 'warning',
                    'bg-red-500' => $a->level === 'danger',
                ])></span>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-zinc-900 dark:text-white">{{ $a->message }}</p>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ empty($a->room_ids) ? __('All screens') : collect($a->room_ids)->map(fn ($id) => $roomNames[$id] ?? '—')->implode(', ') }}
                        ·
                        @if ($a->starts_at) {{ __('from :date', ['date' => $a->starts_at->setTimezone($tz)->format('d/m H:i')]) }} @endif
                        @if ($a->ends_at) {{ __('until :date', ['date' => $a->ends_at->setTimezone($tz)->format('d/m H:i')]) }} @else {{ __('no end date') }} @endif
                    </p>
                </div>
                @if ($active)
                    <flux:badge size="sm" color="green">{{ __('On screen') }}</flux:badge>
                @elseif ($upcoming)
                    <flux:badge size="sm" color="sky">{{ __('Scheduled') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Ended') }}</flux:badge>
                @endif
                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                    <flux:menu>
                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $a->id }})">{{ __('Edit') }}</flux:menu.item>
                        @if ($active)
                            <flux:menu.item icon="stop" wire:click="stop({{ $a->id }})">{{ __('Stop now') }}</flux:menu.item>
                        @endif
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $a->id }})" wire:confirm="{{ __('Delete this announcement?') }}">{{ __('Delete') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        @empty
            <div class="px-5 py-14 text-center">
                <flux:icon.megaphone class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('No announcement yet.') }}</p>
            </div>
        @endforelse
    </x-admin.card>

    <flux:modal name="announcement" variant="flyout" class="w-full max-w-md">
        <form wire:submit="save" class="flex h-full flex-col gap-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit announcement') : __('New announcement') }}</flux:heading>

            <flux:textarea wire:model="message" :label="__('Message')" rows="3" maxlength="200" :placeholder="__('Fire drill at 2 pm: please leave the room.')" required />

            <div>
                <flux:label>{{ __('Level') }}</flux:label>
                <div class="mt-2 grid grid-cols-3 gap-2">
                    @foreach (['info' => [__('Information'), 'bg-sky-500'], 'warning' => [__('Warning'), 'bg-amber-500'], 'danger' => [__('Urgent'), 'bg-red-500']] as $value => [$label, $dot])
                        <label
                            class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition"
                            :class="$wire.level === @js($value) ? 'border-brand ring-2 ring-brand/20' : 'border-zinc-200 dark:border-white/10'"
                        >
                            <input type="radio" wire:model="level" value="{{ $value }}" class="sr-only" />
                            <span class="size-2 rounded-full {{ $dot }}"></span>{{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <flux:switch wire:model="allRooms" :label="__('All screens')" />
                <div class="mt-3 max-h-48 space-y-1 overflow-y-auto" x-show="! $wire.allRooms" x-cloak>
                    @foreach ($this->rooms as $room)
                        <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-white/5">
                            <input type="checkbox" wire:model="roomIds" value="{{ $room->id }}" class="size-4 accent-(--color-brand)" />
                            {{ $room->name }}
                        </label>
                    @endforeach
                </div>
                <flux:error name="roomIds" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="starts_at" type="datetime-local" :label="__('From')" :description="__('Empty = now')" />
                <flux:input wire:model="ends_at" type="datetime-local" :label="__('Until')" :description="__('Empty = no end')" />
            </div>

            <div class="mt-auto flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
