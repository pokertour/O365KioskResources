<?php

use App\Models\AppSetting;
use App\Models\Room;
use App\Services\BookingException;
use App\Services\RoomSchedule;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::mobile')]
#[Title('Room')]
class extends Component {
    #[Locked]
    public Room $room;

    /** Expiry of the signed link (checked again on every action, Livewire updates bypass route middleware). */
    #[Locked]
    public int $expiresAt = 0;

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->expiresAt = (int) request()->query('expires');
    }

    public function valid(): bool
    {
        return now()->getTimestamp() < $this->expiresAt;
    }

    #[Computed]
    public function schedule(): array
    {
        return app(RoomSchedule::class)->today($this->room);
    }

    #[Computed]
    public function status(): array
    {
        return app(RoomSchedule::class)->status($this->schedule['events']);
    }

    public function quickBook(int $minutes): void
    {
        if (! $this->valid() || ! $this->room->booking_enabled || ! in_array($minutes, AppSetting::current()->quickBookDurations(), true)) {
            return;
        }

        $key = 'kiosk-book:'.$this->room->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            Flux::toast(variant: 'warning', text: __('Too many bookings in a short time. Try again in a few minutes.'));

            return;
        }
        RateLimiter::hit($key, 600);

        $schedule = app(RoomSchedule::class);
        $start = $schedule->now()->startOfMinute();
        $end = $start->addMinutes($minutes);
        if ($this->status['until'] && $end->greaterThan($this->status['until'])) {
            $end = $this->status['until'];
        }

        try {
            $schedule->book($this->room, __('Instant booking'), $start, $end);
            Flux::toast(variant: 'success', heading: __('Room booked'), text: fmt_time($start).' – '.fmt_time($end));
        } catch (BookingException $e) {
            Flux::toast(variant: 'danger', heading: __('Not possible'), text: $e->getMessage());
        }

        unset($this->schedule, $this->status);
    }
}; ?>

@php
    $schedule = $this->schedule;
    $status = $this->status;
    $now = app(\App\Services\RoomSchedule::class)->now();
    $rs = app(\App\Services\RoomSchedule::class);
    $state = $status['state'];
    $free = ! $status['busy'] && $status['until'] ? (int) $now->startOfMinute()->diffInMinutes($status['until']) : null;
@endphp

<div class="mx-auto max-w-md px-4 py-6" wire:poll.30s.visible>
    <header class="mb-5 flex items-center gap-3">
        <x-brand-mark size="sm" />
        <span class="truncate text-sm font-semibold">{{ $branding->displayName() }}</span>
    </header>

    <section @class([
        'rounded-3xl p-6 shadow-lg',
        'bg-busy text-busy-foreground' => $state === 'busy',
        'bg-soon text-soon-foreground' => $state === 'soon',
        'bg-available text-available-foreground' => $state === 'free',
    ])>
        <p class="text-sm font-medium opacity-80">{{ $room->location }}</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tight">{{ $room->name }}</h1>
        <p class="mt-4 text-lg font-semibold">
            @if ($state === 'busy')
                {{ __('Busy') }} · {{ __('Free at :time', ['time' => fmt_time($status['until'])]) }}
            @elseif ($status['until'])
                {{ __('Free until :time', ['time' => fmt_time($status['until'])]) }}
            @else
                {{ __('Free all day') }}
            @endif
        </p>

        @if ($this->valid() && $room->booking_enabled && $state !== 'busy' && $schedule['error'] === null && ($free === null || $free >= 5))
            <div class="mt-5 flex flex-wrap gap-2">
                @foreach (\App\Models\AppSetting::current()->quickBookDurations() as $minutes)
                    @if ($free === null || $free >= $minutes || $loop->first)
                        <button type="button" wire:click="quickBook({{ $minutes }})" wire:loading.attr="disabled" class="rounded-xl bg-white/95 px-4 py-2.5 font-semibold text-zinc-900 shadow active:scale-[0.97] disabled:opacity-60">
                            {{ fmt_duration($free !== null && $free < $minutes ? $free : $minutes) }}
                        </button>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    @unless ($this->valid())
        <p class="mt-4 rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">{{ __('This link has expired: scan the code on the screen again to book.') }}</p>
    @endunless

    <section class="mt-6">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Today') }} · {{ fmt_day($now) }}</h2>
        <ul class="space-y-2">
            @forelse ($schedule['events'] as $event)
                @php
                    $past = $event['end']->lessThanOrEqualTo($now);
                @endphp
                <li wire:key="m-{{ md5($event['id']) }}" @class(['flex gap-4 rounded-2xl bg-white p-4 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10', 'opacity-40' => $past])>
                    <div class="w-14 shrink-0 tabular-nums">
                        <div class="font-semibold">{{ $event['is_all_day'] ? __('All day') : fmt_time($event['start']) }}</div>
                        @unless ($event['is_all_day'])
                            <div class="text-sm text-zinc-500">{{ fmt_time($event['end']) }}</div>
                        @endunless
                    </div>
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 font-medium">
                            <span class="truncate">{{ $rs->displaySubject($room, $event) }}</span>
                            @if ($event['online'])
                                <flux:icon.video-camera variant="micro" class="shrink-0 text-zinc-400" />
                            @endif
                        </p>
                        @if ($organizer = $rs->displayOrganizer($room, $event))
                            <p class="truncate text-sm text-zinc-500">{{ $organizer }}</p>
                        @endif
                    </div>
                </li>
            @empty
                <li class="rounded-2xl bg-white p-6 text-center text-zinc-500 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10">{{ __('No meetings scheduled') }}</li>
            @endforelse
        </ul>
    </section>
</div>
