<?php

use App\Models\ActivityLog;
use App\Models\Room;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity')] class extends Component {
    use WithPagination;

    #[Url]
    public string $room = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $type = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rooms()
    {
        return Room::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function logs()
    {
        return ActivityLog::query()
            ->with(['room:id,name,slug', 'user:id,name'])
            ->when($this->room !== '', fn ($q) => $q->where('room_id', (int) $this->room))
            ->when(in_array($this->actor, ['screen', 'admin', 'system'], true), fn ($q) => $q->where('actor', $this->actor))
            ->when(in_array($this->type, ['booking', 'meeting', 'screen', 'issue', 'room', 'announcement', 'settings', 'user', 'auth'], true), fn ($q) => $q->where('action', 'like', $this->type.'.%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);
    }
}; ?>

@php
    $tz = app(\App\Services\RoomSchedule::class)->timezone();
@endphp

<div>
    <x-admin.page-header :title="__('Activity')" :description="__('Everything that happened on the screens and in the administration, kept :days days.', ['days' => \App\Models\ActivityLog::RETENTION_DAYS])" />

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="room" size="sm">
            <flux:select.option value="">{{ __('All rooms') }}</flux:select.option>
            @foreach ($this->rooms as $r)
                <flux:select.option :value="$r->id">{{ $r->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="actor" size="sm">
            <flux:select.option value="">{{ __('Everyone') }}</flux:select.option>
            <flux:select.option value="screen">{{ __('From the screens') }}</flux:select.option>
            <flux:select.option value="admin">{{ __('Administrators') }}</flux:select.option>
            <flux:select.option value="system">{{ __('Automatic') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="type" size="sm">
            <flux:select.option value="">{{ __('All events') }}</flux:select.option>
            <flux:select.option value="booking">{{ __('Bookings') }}</flux:select.option>
            <flux:select.option value="meeting">{{ __('Meetings ended or extended') }}</flux:select.option>
            <flux:select.option value="screen">{{ __('Screens') }}</flux:select.option>
            <flux:select.option value="issue">{{ __('Issue reports') }}</flux:select.option>
            <flux:select.option value="room">{{ __('Rooms') }}</flux:select.option>
            <flux:select.option value="announcement">{{ __('Announcements') }}</flux:select.option>
            <flux:select.option value="settings">{{ __('Settings') }}</flux:select.option>
            <flux:select.option value="user">{{ __('Administrators') }}</flux:select.option>
        </flux:select>
    </div>

    <x-admin.card :padded="false">
        @forelse ($this->logs as $log)
            <div wire:key="log-{{ $log->id }}" class="flex items-start gap-3 border-b border-zinc-100 px-5 py-3 last:border-0 dark:border-white/[0.06]">
                <div class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-white/5">
                    <flux:icon :name="$log->icon()" variant="micro" class="text-zinc-500" />
                </div>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="text-zinc-900 dark:text-white">
                        <span class="font-medium">{{ $log->label() }}</span>
                        @if ($log->description) <span class="text-zinc-500">· {{ $log->description }}</span> @endif
                    </p>
                    <p class="text-xs text-zinc-500">
                        @if ($log->room)
                            <a href="{{ route('admin.rooms.show', $log->room) }}" wire:navigate class="font-medium hover:text-zinc-900 dark:hover:text-white">{{ $log->room->name }}</a> ·
                        @endif
                        {{ $log->actor === 'screen' ? __('From the screen') : ($log->actor === 'system' ? __('Automatic') : ($log->user?->name ?? __('Administrator'))) }}
                        @if ($log->ip && $log->actor !== 'system') · <span class="font-mono">{{ $log->ip }}</span> @endif
                    </p>
                </div>
                <time class="shrink-0 text-xs tabular-nums text-zinc-500" datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->diffForHumans() }}">
                    {{ $log->created_at->setTimezone($tz)->format('d/m H:i') }}
                </time>
            </div>
        @empty
            <p class="px-5 py-14 text-center text-sm text-zinc-500">{{ __('Nothing yet.') }}</p>
        @endforelse
    </x-admin.card>

    <div class="mt-4">
        {{ $this->logs->links() }}
    </div>
</div>
