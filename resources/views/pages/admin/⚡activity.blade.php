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
            ->when(in_array($this->type, ['booking', 'meeting', 'screen', 'issue', 'room', 'announcement', 'signage', 'settings', 'user', 'auth'], true), fn ($q) => $q->where('action', 'like', $this->type.'.%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);
    }
}; ?>

<div>
    <x-admin.page-header icon="clock" :title="__('Activity')" :description="__('Everything that happened on the screens and in the administration, kept :days days.', ['days' => \App\Models\ActivityLog::RETENTION_DAYS])" />

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
            <flux:select.option value="signage">{{ __('Wayfinding') }}</flux:select.option>
            <flux:select.option value="settings">{{ __('Settings') }}</flux:select.option>
            <flux:select.option value="user">{{ __('Administrators') }}</flux:select.option>
        </flux:select>
    </div>

    <x-admin.card :padded="false">
        @if ($this->logs->isEmpty())
            <div class="px-5 py-16 text-center">
                <flux:icon.clock class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('Nothing yet.') }}</p>
            </div>
        @else
            <x-admin.activity-timeline :logs="$this->logs->getCollection()" show-ip />
        @endif
    </x-admin.card>

    <div class="mt-4">
        {{ $this->logs->links() }}
    </div>
</div>
