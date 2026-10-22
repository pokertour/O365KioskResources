<?php

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\BrandingSetting;
use App\Models\IssueReport;
use App\Models\Room;
use App\Models\Screen;
use App\Models\ScreenPairing;
use App\Services\BookingException;
use App\Services\RoomSchedule;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Room')] class extends Component {
    #[Locked]
    public Room $room;

    #[Url]
    public string $tab = 'overview';

    // Room settings
    public string $name = '';

    public string $o365_resource_email = '';

    public string $location = '';

    public ?int $capacity = null;

    public bool $booking_enabled = true;

    public bool $show_subjects = true;

    /** @var list<string> */
    public array $equipment = [];

    // Screen overrides ('' = inherit the global setting)
    public string $o_theme = '';

    public string $o_text_scale = '';

    public bool $o_hours = false;

    public string $o_day_start = '08:00';

    public string $o_day_end = '19:00';

    public string $o_night = '';

    public string $o_night_start = '20:00';

    public string $o_night_end = '07:00';

    // Pairing ('new' or the id of the screen whose tablet is replaced)
    public string $pairing_code = '';

    public string $pairing_target = 'new';

    public string $pairing_name = '';

    // Screen being added / edited
    public string $new_screen_name = '';

    public ?int $editingScreenId = null;

    public string $s_name = '';

    public string $s_theme = '';

    public string $s_text_scale = '';

    public string $s_night = '';

    public string $s_night_start = '20:00';

    public string $s_night_end = '07:00';

    // Booking / editing form
    public string $subject = '';

    public string $start_time = '';

    public string $end_time = '';

    public ?string $pendingDeleteEventId = null;

    public ?string $editingEventId = null;

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->fillSettings();
    }

    private function fillSettings(): void
    {
        $r = $this->room;
        $global = AppSetting::current();

        $this->name = $r->name;
        $this->o365_resource_email = $r->o365_resource_email;
        $this->location = (string) $r->location;
        $this->capacity = $r->capacity;
        $this->booking_enabled = $r->booking_enabled;
        $this->show_subjects = $r->show_subjects;
        $this->equipment = $r->equipmentList();

        $this->o_theme = (string) $r->kiosk_theme;
        $this->o_text_scale = $r->text_scale ? (string) $r->text_scale : '';
        $this->o_hours = $r->day_start !== null;
        $this->o_day_start = $r->day_start ?? $global->day_start;
        $this->o_day_end = $r->day_end ?? $global->day_end;
        $this->o_night = $r->night_mode === null ? '' : ($r->night_mode ? 'on' : 'off');
        $this->o_night_start = $r->night_start ?? $global->night_start;
        $this->o_night_end = $r->night_end ?? $global->night_end;
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

    #[Computed]
    public function screens()
    {
        return $this->room->screens()->get();
    }

    #[Computed]
    public function editingScreen(): ?Screen
    {
        return $this->editingScreenId ? $this->room->screens()->whereKey($this->editingScreenId)->first() : null;
    }

    #[Computed]
    public function issues()
    {
        return $this->room->issueReports()->with('resolver')->latest()->limit(30)->get();
    }

    #[Computed]
    public function activity()
    {
        return ActivityLog::query()->where('room_id', $this->room->id)->with('user')->latest('created_at')->limit(50)->get();
    }

    public function refreshCalendar(): void
    {
        app(RoomSchedule::class)->forget($this->room);
        unset($this->schedule, $this->status);
    }

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'o365_resource_email' => ['required', 'email:rfc', 'max:255', Rule::unique('rooms', 'o365_resource_email')->ignore($this->room->id)],
            'location' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'booking_enabled' => ['boolean'],
            'show_subjects' => ['boolean'],
            'equipment' => ['array'],
            'equipment.*' => [Rule::in(array_keys(Room::EQUIPMENT))],
        ], [], [
            'name' => __('name'),
            'o365_resource_email' => __('room address'),
            'location' => __('location'),
            'capacity' => __('capacity'),
        ]);

        $emailChanged = strtolower($validated['o365_resource_email']) !== $this->room->o365_resource_email;
        $this->room->fill([
            ...$validated,
            'o365_resource_email' => strtolower($validated['o365_resource_email']),
            'location' => trim((string) $validated['location']) ?: null,
            'equipment' => array_values(array_unique($validated['equipment'])),
        ])->save();
        ActivityLog::record('room.updated', $this->room);

        if ($emailChanged) {
            $this->refreshCalendar();
        }

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function saveScreenSettings(): void
    {
        $this->validate([
            'o_theme' => ['nullable', Rule::in(['', ...BrandingSetting::THEMES])],
            'o_text_scale' => ['nullable', Rule::in(['', ...array_map('strval', AppSetting::TEXT_SCALES)])],
            'o_day_start' => ['required_if:o_hours,true', 'date_format:H:i'],
            'o_day_end' => ['required_if:o_hours,true', 'date_format:H:i', 'after:o_day_start'],
            'o_night' => ['nullable', Rule::in(['', 'on', 'off'])],
            'o_night_start' => ['required_if:o_night,on', 'date_format:H:i'],
            'o_night_end' => ['required_if:o_night,on', 'date_format:H:i', 'different:o_night_start'],
        ], [], [
            'o_day_start' => __('start'),
            'o_day_end' => __('end'),
            'o_night_start' => __('start'),
            'o_night_end' => __('end'),
        ]);

        $this->room->forceFill([
            'kiosk_theme' => $this->o_theme ?: null,
            'text_scale' => $this->o_text_scale !== '' ? (int) $this->o_text_scale : null,
            'day_start' => $this->o_hours ? $this->o_day_start : null,
            'day_end' => $this->o_hours ? $this->o_day_end : null,
            'night_mode' => match ($this->o_night) { 'on' => true, 'off' => false, default => null },
            'night_start' => $this->o_night === 'on' ? $this->o_night_start : null,
            'night_end' => $this->o_night === 'on' ? $this->o_night_end : null,
        ])->save();
        ActivityLog::record('room.updated', $this->room, __('Screen settings'));

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function openPairing(?int $screenId = null): void
    {
        $this->pairing_code = '';
        $this->pairing_target = $screenId ? (string) $screenId : 'new';
        $this->pairing_name = __('Screen :n', ['n' => $this->screens->count() + 1]);
        $this->resetValidation();
        $this->modal('pair-screen')->show();
    }

    public function pairScreen(): void
    {
        $this->validate([
            'pairing_code' => ['required', 'digits:6'],
            'pairing_target' => ['required', Rule::in(['new', ...$this->screens->pluck('id')->map(fn ($id) => (string) $id)])],
            'pairing_name' => ['required_if:pairing_target,new', 'nullable', 'string', 'max:60'],
        ], [], ['pairing_code' => __('code'), 'pairing_name' => __('name')]);

        $pairing = ScreenPairing::query()
            ->where('code', $this->pairing_code)
            ->whereNull('screen_id')
            ->where('expires_at', '>', now())
            ->first();

        if (! $pairing) {
            $this->addError('pairing_code', __('Unknown or expired code. Check the code displayed on the screen.'));

            return;
        }

        if ($this->pairing_target === 'new') {
            $screen = $this->room->screens()->create(['name' => trim($this->pairing_name)]);
        } else {
            // Replacing the tablet of an existing screen: the previous device is disconnected.
            $screen = $this->room->screens()->findOrFail((int) $this->pairing_target);
            $screen->rotateToken();
        }

        $pairing->forceFill(['room_id' => $this->room->id, 'screen_id' => $screen->id])->save();
        ActivityLog::record('screen.paired', $this->room, $screen->name);

        $this->pairing_code = '';
        unset($this->screens);
        $this->modal('pair-screen')->close();
        Flux::toast(variant: 'success', heading: __('Screen paired'), text: __('It will show the room in a few seconds.'));
    }

    public function openAddScreen(): void
    {
        $this->new_screen_name = __('Screen :n', ['n' => $this->screens->count() + 1]);
        $this->resetValidation();
        $this->modal('add-screen')->show();
    }

    public function addScreen(): void
    {
        $this->validate(['new_screen_name' => ['required', 'string', 'max:60']], [], ['new_screen_name' => __('name')]);

        $screen = $this->room->screens()->create(['name' => trim($this->new_screen_name)]);
        ActivityLog::record('screen.added', $this->room, $screen->name);
        unset($this->screens);
        $this->modal('add-screen')->close();
        $this->openScreen($screen->id);
    }

    public function openScreen(int $id): void
    {
        $screen = $this->room->screens()->whereKey($id)->firstOrFail();
        $this->editingScreenId = $screen->id;
        $this->s_name = $screen->name;
        $this->s_theme = (string) $screen->kiosk_theme;
        $this->s_text_scale = $screen->text_scale ? (string) $screen->text_scale : '';
        $this->s_night = $screen->night_mode === null ? '' : ($screen->night_mode ? 'on' : 'off');
        $this->s_night_start = $screen->night_start ?? $this->o_night_start;
        $this->s_night_end = $screen->night_end ?? $this->o_night_end;
        unset($this->editingScreen);
        $this->resetValidation();
        $this->modal('screen-edit')->show();
    }

    public function saveScreen(): void
    {
        $screen = $this->editingScreen;
        if (! $screen) {
            return;
        }

        $this->validate([
            's_name' => ['required', 'string', 'max:60'],
            's_theme' => ['nullable', Rule::in(['', ...BrandingSetting::THEMES])],
            's_text_scale' => ['nullable', Rule::in(['', ...array_map('strval', AppSetting::TEXT_SCALES)])],
            's_night' => ['nullable', Rule::in(['', 'on', 'off'])],
            's_night_start' => ['required_if:s_night,on', 'date_format:H:i'],
            's_night_end' => ['required_if:s_night,on', 'date_format:H:i', 'different:s_night_start'],
        ], [], ['s_name' => __('name'), 's_night_start' => __('start'), 's_night_end' => __('end')]);

        $screen->fill([
            'name' => trim($this->s_name),
            'kiosk_theme' => $this->s_theme ?: null,
            'text_scale' => $this->s_text_scale !== '' ? (int) $this->s_text_scale : null,
            'night_mode' => match ($this->s_night) { 'on' => true, 'off' => false, default => null },
            'night_start' => $this->s_night === 'on' ? $this->s_night_start : null,
            'night_end' => $this->s_night === 'on' ? $this->s_night_end : null,
        ])->save();
        ActivityLog::record('room.updated', $this->room, __('Screen settings').' · '.$screen->name);

        unset($this->screens, $this->editingScreen);
        $this->modal('screen-edit')->close();
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function rotateScreen(): void
    {
        $screen = $this->editingScreen;
        if (! $screen) {
            return;
        }

        $screen->rotateToken();
        ActivityLog::record('screen.link_rotated', $this->room, $screen->name);
        unset($this->screens, $this->editingScreen);
        Flux::toast(variant: 'success', heading: __('New link generated'), text: __('The previous link no longer works. Update the screen.'));
    }

    public function deleteScreen(): void
    {
        $screen = $this->editingScreen;
        if (! $screen) {
            return;
        }

        ActivityLog::record('screen.removed', $this->room, $screen->name);
        $screen->delete();
        $this->editingScreenId = null;
        unset($this->screens, $this->editingScreen);
        $this->modal('screen-edit')->close();
        Flux::toast(variant: 'success', text: __('Screen removed. Its device is disconnected.'));
    }

    public function resolveIssue(int $id): void
    {
        $issue = $this->room->issueReports()->whereKey($id)->whereNull('resolved_at')->first();
        if (! $issue) {
            return;
        }

        $issue->forceFill(['resolved_at' => now(), 'resolved_by' => auth()->id()])->save();
        ActivityLog::record('issue.resolved', $this->room, IssueReport::categoryLabel($issue->category));
        unset($this->issues);
        Flux::toast(variant: 'success', text: __('Issue marked as resolved.'));
    }

    public function openBookingForm(): void
    {
        $start = app(RoomSchedule::class)->now()->ceilMinutes(15);
        $this->subject = '';
        $this->start_time = $start->format('H:i');
        $this->end_time = $start->addMinutes(30)->format('H:i');
        $this->resetValidation(['subject', 'start_time', 'end_time']);
        $this->modal('admin-book')->show();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function formTimes(): array
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ], [
            'end_time.after' => __('The end time must be after the start time.'),
        ], [
            'subject' => __('subject'),
        ]);

        $schedule = app(RoomSchedule::class);
        $tz = $schedule->timezone();
        $day = $schedule->now()->toDateString();

        return [
            CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.$this->start_time, $tz),
            CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.$this->end_time, $tz),
        ];
    }

    public function createReservation(): void
    {
        [$start, $end] = $this->formTimes();

        try {
            app(RoomSchedule::class)->book($this->room, $this->subject, $start, $end);
        } catch (BookingException $e) {
            $this->addError('start_time', $e->getMessage());

            return;
        }

        $this->reset('subject', 'start_time', 'end_time');
        $this->modal('admin-book')->close();
        unset($this->schedule, $this->status, $this->activity);
        Flux::toast(variant: 'success', text: __('Booking created.'));
    }

    public function openEditEvent(string $eventId): void
    {
        $event = collect($this->schedule['events'])->firstWhere('id', $eventId);
        if (! $event) {
            return;
        }

        $this->editingEventId = $eventId;
        $this->subject = $event['subject'];
        $this->start_time = $event['start']->format('H:i');
        $this->end_time = $event['end']->format('H:i');
        $this->resetValidation(['subject', 'start_time', 'end_time']);
        $this->modal('admin-edit')->show();
    }

    public function saveEditEvent(): void
    {
        if ($this->editingEventId === null) {
            return;
        }

        [$start, $end] = $this->formTimes();

        try {
            app(RoomSchedule::class)->reschedule($this->room, $this->editingEventId, $this->subject, $start, $end);
        } catch (BookingException $e) {
            $this->addError('start_time', $e->getMessage());

            return;
        }

        $this->editingEventId = null;
        $this->reset('subject', 'start_time', 'end_time');
        $this->modal('admin-edit')->close();
        unset($this->schedule, $this->status, $this->activity);
        Flux::toast(variant: 'success', text: __('Meeting updated.'));
    }

    public function openDeleteEventModal(string $eventId): void
    {
        $this->pendingDeleteEventId = $eventId;
        $this->modal('confirm-delete-event')->show();
    }

    public function confirmDeleteEvent(): void
    {
        $id = $this->pendingDeleteEventId;
        $this->pendingDeleteEventId = null;
        $this->modal('confirm-delete-event')->close();

        // Only events of this room's agenda may be cancelled from here.
        if ($id === null || ! collect($this->schedule['events'])->contains('id', $id)) {
            return;
        }

        try {
            app(RoomSchedule::class)->cancel($this->room, $id);
            Flux::toast(variant: 'success', text: __('Booking cancelled.'));
        } catch (\Throwable $e) {
            Log::warning('Admin delete event failed', ['exception' => $e->getMessage()]);
            Flux::toast(variant: 'danger', heading: __('Cancellation failed'), text: $e->getMessage());
        }
        unset($this->schedule, $this->status, $this->activity);
    }

    public function deleteRoom(): void
    {
        ActivityLog::record('room.deleted', null, $this->room->name);
        $this->room->delete();
        Flux::toast(variant: 'success', text: __('Room deleted.'));
        $this->redirect(route('admin.rooms.index'), navigate: true);
    }
}; ?>

@php
    $schedule = $this->schedule;
    $status = $this->status;
    $screens = $this->screens;
    $onlineCount = $screens->filter(fn ($s) => $s->isOnline())->count();
    $everSeen = $screens->whereNotNull('seen_at')->count();
    $kioskUrl = $screens->first()?->kioskUrl();
    $now = app(\App\Services\RoomSchedule::class)->now();
    $openIssues = $this->issues->whereNull('resolved_at');
    $tabs = [
        'overview' => [__('Overview'), 'calendar-days'],
        'screen' => [__('Screen'), 'tv'],
        'settings' => [__('Settings'), 'cog-6-tooth'],
        'activity' => [__('Activity'), 'clock'],
    ];
@endphp

<div wire:poll.60s.visible>
    <x-admin.page-header :title="$room->name" :back="route('admin.rooms.index')" :back-label="__('Rooms')">
        <x-slot:meta>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                @if ($schedule['error'] !== 'not_configured')
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-busy/10 text-busy' => $status['state'] === 'busy',
                        'bg-soon/10 text-soon' => $status['state'] === 'soon',
                        'bg-available/10 text-available' => $status['state'] === 'free',
                    ])>
                        <span class="size-1.5 rounded-full bg-current"></span>
                        {{ ['busy' => __('Busy'), 'soon' => __('Starting soon'), 'free' => __('Available')][$status['state']] }}
                    </span>
                @endif
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-available/10 text-available' => $everSeen > 0 && $onlineCount === $everSeen,
                    'bg-amber-500/10 text-amber-700 dark:text-amber-300' => $onlineCount < $everSeen,
                    'bg-zinc-100 text-zinc-500 dark:bg-white/5' => $everSeen === 0,
                ])>
                    <flux:icon.tv variant="micro" />
                    {{ $everSeen === 0 ? __('No screen yet') : __(':online/:total screens online', ['online' => $onlineCount, 'total' => $everSeen]) }}
                </span>
                <span class="text-zinc-500">{{ $room->o365_resource_email }}</span>
            </div>
        </x-slot:meta>
        <x-slot:actions>
            @if ($kioskUrl)
                <flux:button icon="arrow-top-right-on-square" :href="$kioskUrl" target="_blank" rel="noopener noreferrer">{{ __('Open screen') }}</flux:button>
            @endif
            <flux:button variant="primary" icon="plus" wire:click="openBookingForm">{{ __('Book') }}</flux:button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Tabs --}}
    <nav class="-mt-2 mb-6 flex gap-1 overflow-x-auto [scrollbar-width:none] border-b border-zinc-200 dark:border-white/[0.07]" role="tablist">
        @foreach ($tabs as $key => [$label, $icon])
            <button
                type="button"
                role="tab"
                wire:click="$set('tab', '{{ $key }}')"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition',
                    'border-brand text-zinc-900 dark:text-white' => $tab === $key,
                    'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => $tab !== $key,
                ])
            >
                <flux:icon :name="$icon" variant="mini" @class(['size-4', 'text-brand' => $tab === $key]) />
                {{ $label }}
                @if ($key === 'activity' && $openIssues->count() > 0)
                    <span class="rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white">{{ $openIssues->count() }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- ===================== Overview ===================== --}}
    @if ($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <x-admin.card :title="__('Today’s agenda')" :description="ucfirst(fmt_day($now))" :padded="false">
                <x-slot:headerActions>
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshCalendar" wire:loading.attr="disabled" wire:target="refreshCalendar">
                        {{ __('Refresh') }}
                    </flux:button>
                </x-slot:headerActions>

                @if ($schedule['error'] === 'not_configured')
                    <div class="p-5">
                        <flux:callout icon="cloud" color="amber">
                            <flux:callout.heading>{{ __('Microsoft 365 connection required') }}</flux:callout.heading>
                            <flux:callout.text>
                                <flux:callout.link :href="route('admin.microsoft')" wire:navigate>{{ __('Configure the connection') }}</flux:callout.link>
                            </flux:callout.text>
                        </flux:callout>
                    </div>
                @elseif ($schedule['stale'])
                    <div class="p-5 pb-0">
                        <flux:callout icon="exclamation-triangle" color="red">
                            <flux:callout.heading>{{ __('Unable to read the calendar') }}</flux:callout.heading>
                            <flux:callout.text class="break-words">{{ $schedule['error'] }}</flux:callout.text>
                        </flux:callout>
                    </div>
                @endif

                @if (count($schedule['events']) === 0)
                    @if ($schedule['error'] === null)
                        <div class="px-5 py-14 text-center">
                            <flux:icon.calendar-days class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <p class="mt-3 text-sm text-zinc-500">{{ __('No meetings today.') }}</p>
                        </div>
                    @endif
                @else
                    <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach ($schedule['events'] as $event)
                            @php
                                $isPast = $event['end']->lessThanOrEqualTo($now);
                                $isCurrent = $status['current'] && $status['current']['id'] === $event['id'];
                            @endphp
                            <li wire:key="ev-{{ md5($event['id']) }}" @class(['group flex items-center gap-4 px-5 py-3.5', 'opacity-50' => $isPast])>
                                <div class="w-24 shrink-0 text-sm tabular-nums">
                                    @if ($event['is_all_day'])
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ __('All day') }}</span>
                                    @else
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ fmt_time($event['start']) }}</span>
                                        <span class="text-zinc-400">– {{ fmt_time($event['end']) }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-2 truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $event['is_private'] ? __('Private') : ($event['subject'] ?: __('Untitled')) }}
                                        @if ($event['online'])
                                            <flux:icon.video-camera variant="micro" class="shrink-0 text-zinc-400" />
                                        @endif
                                        @if ($isCurrent)
                                            <span class="rounded-full bg-busy/10 px-2 py-0.5 text-[11px] font-semibold text-busy">{{ __('Now') }}</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-xs text-zinc-500">
                                        {{ app(\App\Services\RoomSchedule::class)->isAppBooking($room, $event) ? __('Booked from the app') : $event['organizer'] }}
                                    </p>
                                </div>
                                @unless ($isPast)
                                    <flux:button
                                        size="sm"
                                        variant="subtle"
                                        icon="pencil-square"
                                        class="opacity-0 transition group-hover:opacity-100 focus:opacity-100 max-lg:opacity-100"
                                        wire:click="openEditEvent({{ \Illuminate\Support\Js::from($event['id']) }})"
                                        :aria-label="__('Edit meeting')"
                                    />
                                    <flux:button
                                        size="sm"
                                        variant="subtle"
                                        icon="trash"
                                        class="opacity-0 transition group-hover:opacity-100 focus:opacity-100 max-lg:opacity-100"
                                        wire:click="openDeleteEventModal({{ \Illuminate\Support\Js::from($event['id']) }})"
                                        :aria-label="__('Cancel booking')"
                                    />
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            <div class="flex flex-col gap-6">
                @if ($openIssues->isNotEmpty())
                    <x-admin.card :title="__('Open issues')" :padded="false">
                        <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach ($openIssues as $issue)
                                <li wire:key="oi-{{ $issue->id }}" class="flex items-start gap-3 px-5 py-3">
                                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0 text-amber-500" />
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ \App\Models\IssueReport::categoryLabel($issue->category) }}</p>
                                        @if ($issue->message)
                                            <p class="mt-0.5 break-words text-zinc-600 dark:text-zinc-400">{{ $issue->message }}</p>
                                        @endif
                                        <p class="mt-0.5 text-xs text-zinc-400">{{ $issue->created_at->diffForHumans() }}</p>
                                    </div>
                                    <flux:button size="xs" variant="ghost" icon="check" wire:click="resolveIssue({{ $issue->id }})">{{ __('Resolved') }}</flux:button>
                                </li>
                            @endforeach
                        </ul>
                    </x-admin.card>
                @endif

                <x-admin.card :title="trans_choice('Screen|Screens', $screens->count())" :padded="false">
                    <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach ($screens as $screen)
                            <li wire:key="ov-screen-{{ $screen->id }}" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                                <span class="truncate text-zinc-900 dark:text-white">{{ $screen->name }}</span>
                                <x-admin.screen-status :screen="$screen" />
                            </li>
                        @endforeach
                    </ul>
                    <div class="border-t border-zinc-100 px-3 py-2 dark:border-white/[0.06]">
                        <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" wire:click="$set('tab', 'screen')">{{ __('Manage the screens') }}</flux:button>
                    </div>
                </x-admin.card>

                @if ($room->equipmentList())
                    <x-admin.card :title="__('Equipment')">
                        <ul class="flex flex-wrap gap-2">
                            @foreach ($room->equipmentList() as $key)
                                <li class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 dark:bg-white/5 dark:text-zinc-300">
                                    <flux:icon :name="\App\Models\Room::EQUIPMENT[$key]" variant="micro" />{{ \App\Models\Room::equipmentLabel($key) }}
                                </li>
                            @endforeach
                        </ul>
                    </x-admin.card>
                @endif
            </div>
        </div>
    @endif

    {{-- ===================== Screens ===================== --}}
    @if ($tab === 'screen')
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex flex-col gap-6">
                <x-admin.card :title="__('Screens of this room')" :description="__('Each screen has its own link, its own supervision and can have its own settings.')" :padded="false">
                    <x-slot:headerActions>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="primary" icon="plus" icon:trailing="chevron-down">{{ __('Add a screen') }}</flux:button>
                            <flux:menu>
                                <flux:menu.item icon="link" wire:click="openPairing">{{ __('With a pairing code') }}</flux:menu.item>
                                <flux:menu.item icon="clipboard" wire:click="openAddScreen">{{ __('With a direct link') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </x-slot:headerActions>

                    @forelse ($screens as $screen)
                        @php
                            $info = $screen->info ?? [];
                        @endphp
                        <div wire:key="screen-{{ $screen->id }}" class="flex flex-wrap items-center gap-4 border-b border-zinc-100 px-5 py-4 last:border-0 dark:border-white/[0.06]">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-white/5">
                                <flux:icon :name="! empty($info['width']) && $info['height'] > $info['width'] ? 'device-tablet' : 'tv'" class="size-5 text-zinc-500" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $screen->name }}</span>
                                    <x-admin.screen-status :screen="$screen" />
                                    @if ($screen->hasOverrides())
                                        <flux:badge size="sm" color="zinc">{{ __('Own settings') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="mt-0.5 truncate text-xs text-zinc-500">
                                    @if ($screen->seen_at)
                                        {{ __('Last seen') }} {{ $screen->seen_at->diffForHumans() }}
                                        @if (! empty($info['width'])) · {{ $info['width'] }} × {{ $info['height'] }} @endif
                                        @if (! empty($info['device'])) · {{ $info['device'] }} @endif
                                    @else
                                        {{ __('Never connected yet') }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                @if ($url = $screen->kioskUrl())
                                    <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$url" target="_blank" rel="noopener noreferrer" :aria-label="__('Open screen')" />
                                @endif
                                <flux:button size="sm" variant="ghost" icon="cog-6-tooth" wire:click="openScreen({{ $screen->id }})">{{ __('Manage') }}</flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-12 text-center">
                            <flux:icon.tv class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <p class="mt-3 text-sm text-zinc-500">{{ __('No screen for this room.') }}</p>
                            <flux:button size="sm" variant="primary" class="mt-4" icon="link" wire:click="openPairing">{{ __('Pair a screen') }}</flux:button>
                        </div>
                    @endforelse
                </x-admin.card>

                <div class="rounded-xl bg-zinc-50 p-5 text-sm dark:bg-white/[0.03]">
                    <p class="font-medium text-zinc-900 dark:text-white">{{ __('Install a screen') }}</p>
                    <p class="mt-1 text-zinc-500">{!! __('On the tablet, open :url: a 6-digit code appears. Then “Add a screen › With a pairing code” here.', ['url' => '<code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-white/10">'.e(route('kiosk.pair')).'</code>']) !!}</p>
                    <p class="mt-2 text-xs text-zinc-500">{{ __('Administrators are alerted when a screen stops reporting (see Settings › Notifications).') }}</p>
                </div>
            </div>

            <form wire:submit="saveScreenSettings">
                <x-admin.card :title="__('Settings shared by the screens')" :description="__('Apply to every screen of this room. “Default” uses Settings › Screens and Branding; a screen can still override them.')">
                    <div class="space-y-5">
                        <flux:select wire:model="o_theme" :label="__('Theme')">
                            <flux:select.option value="">{{ __('Default') }} ({{ $branding->kioskTheme() === 'dark' ? __('Dark') : __('Light') }})</flux:select.option>
                            <flux:select.option value="dark">{{ __('Dark') }}</flux:select.option>
                            <flux:select.option value="light">{{ __('Light') }}</flux:select.option>
                        </flux:select>

                        <flux:select wire:model="o_text_scale" :label="__('Text size')">
                            <flux:select.option value="">{{ __('Default') }}</flux:select.option>
                            @foreach ([90 => __('Small'), 100 => __('Normal'), 115 => __('Large'), 130 => __('Extra large')] as $scale => $label)
                                <flux:select.option :value="$scale">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div>
                            <flux:switch wire:model="o_hours" :label="__('Own working hours')" />
                            <div class="mt-3 grid grid-cols-2 gap-3" x-show="$wire.o_hours" x-cloak>
                                <flux:input wire:model="o_day_start" type="time" :aria-label="__('From')" />
                                <flux:input wire:model="o_day_end" type="time" :aria-label="__('Until')" />
                            </div>
                            <flux:error name="o_day_end" />
                        </div>

                        <div>
                            <flux:select wire:model="o_night" :label="__('Night mode')">
                                <flux:select.option value="">{{ __('Default') }}</flux:select.option>
                                <flux:select.option value="on">{{ __('Enabled for this room') }}</flux:select.option>
                                <flux:select.option value="off">{{ __('Disabled for this room') }}</flux:select.option>
                            </flux:select>
                            <div class="mt-3 grid grid-cols-2 gap-3" x-show="$wire.o_night === 'on'" x-cloak>
                                <flux:input wire:model="o_night_start" type="time" :aria-label="__('From')" />
                                <flux:input wire:model="o_night_end" type="time" :aria-label="__('Until')" />
                            </div>
                            <flux:error name="o_night_end" />
                        </div>
                    </div>
                    <x-slot:footer>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                    </x-slot:footer>
                </x-admin.card>
            </form>
        </div>
    @endif

    {{-- ===================== Settings ===================== --}}
    @if ($tab === 'settings')
        <div class="grid max-w-3xl gap-6">
            <form wire:submit="saveSettings">
                <x-admin.card :title="__('Room')">
                    <div class="space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="name" :label="__('Name')" required />
                            <flux:input wire:model="o365_resource_email" type="email" :label="__('Microsoft 365 address')" required />
                        </div>
                        <div class="grid grid-cols-[1fr_8rem] gap-4">
                            <flux:input wire:model="location" :label="__('Location')" />
                            <flux:input wire:model="capacity" type="number" min="1" :label="__('Capacity')" />
                        </div>

                        <div>
                            <flux:label>{{ __('Equipment') }}</flux:label>
                            <flux:description class="mt-1">{{ __('Shown on the screen. People can report a problem with each item.') }}</flux:description>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (\App\Models\Room::EQUIPMENT as $key => $icon)
                                    <label
                                        class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition"
                                        :class="$wire.equipment.includes(@js($key)) ? 'border-brand bg-brand/10 text-zinc-900 dark:text-white' : 'border-zinc-200 text-zinc-600 hover:border-zinc-300 dark:border-white/10 dark:text-zinc-300'"
                                    >
                                        <input type="checkbox" wire:model="equipment" value="{{ $key }}" class="sr-only" />
                                        <flux:icon :name="$icon" variant="mini" class="size-4" />
                                        {{ \App\Models\Room::equipmentLabel($key) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <flux:separator variant="subtle" />
                        <flux:switch wire:model="booking_enabled" :label="__('Booking from the screen')" :description="__('Lets people book the room directly on its screen.')" />
                        <flux:switch wire:model="show_subjects" :label="__('Show meeting subjects')" :description="__('Otherwise meetings are shown as “Reserved”.')" />
                    </div>
                    <x-slot:footer>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                    </x-slot:footer>
                </x-admin.card>
            </form>

            <div class="rounded-xl border border-red-200 p-5 dark:border-red-500/20">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Delete room') }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('The screen will stop working. The Microsoft 365 calendar is not changed.') }}</p>
                <flux:modal.trigger name="confirm-delete-room">
                    <flux:button size="sm" variant="danger" class="mt-4">{{ __('Delete…') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    @endif

    {{-- ===================== Activity ===================== --}}
    @if ($tab === 'activity')
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <x-admin.card :title="__('Activity log')" :description="__('Last 50 events for this room.')" :padded="false">
                @forelse ($this->activity as $log)
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
                                {{ $log->actor === 'screen' ? __('From the screen') : ($log->actor === 'system' ? __('Automatic') : ($log->user?->name ?? __('Administrator'))) }}
                                · <span title="{{ $log->created_at->setTimezone(app(\App\Services\RoomSchedule::class)->timezone())->format('d/m/Y H:i') }}">{{ $log->created_at->diffForHumans() }}</span>
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('Nothing yet.') }}</p>
                @endforelse
            </x-admin.card>

            <x-admin.card :title="__('Issue reports')" :padded="false">
                @forelse ($this->issues as $issue)
                    <div wire:key="is-{{ $issue->id }}" class="flex items-start gap-3 border-b border-zinc-100 px-5 py-3 last:border-0 dark:border-white/[0.06]">
                        <flux:icon :name="$issue->resolved_at ? 'check-circle' : 'exclamation-triangle'" variant="mini" @class(['mt-0.5 shrink-0', 'text-available' => $issue->resolved_at, 'text-amber-500' => ! $issue->resolved_at]) />
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ \App\Models\IssueReport::categoryLabel($issue->category) }}</p>
                            @if ($issue->message)
                                <p class="break-words text-zinc-600 dark:text-zinc-400">{{ $issue->message }}</p>
                            @endif
                            <p class="text-xs text-zinc-400">
                                {{ $issue->created_at->diffForHumans() }}
                                @if ($issue->resolved_at) · {{ __('resolved by :name', ['name' => $issue->resolver?->name ?? '—']) }} @endif
                            </p>
                        </div>
                        @unless ($issue->resolved_at)
                            <flux:button size="xs" variant="ghost" icon="check" wire:click="resolveIssue({{ $issue->id }})">{{ __('Resolved') }}</flux:button>
                        @endunless
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('No issue reported.') }}</p>
                @endforelse
            </x-admin.card>
        </div>
    @endif

    {{-- ===================== Dialogs ===================== --}}
    <flux:modal name="pair-screen" class="w-full max-w-sm">
        <form wire:submit="pairScreen" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Pair a screen') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Enter the 6-digit code displayed on the screen.') }}</flux:text>
            </div>
            <flux:input wire:model="pairing_code" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="000000" class:input="text-center font-mono text-2xl tracking-[0.4em]" autofocus />
            <flux:select wire:model="pairing_target" :label="__('This tablet is')">
                <flux:select.option value="new">{{ __('A new screen') }}</flux:select.option>
                @foreach ($screens as $screen)
                    <flux:select.option :value="$screen->id">{{ __('The replacement of “:name”', ['name' => $screen->name]) }}</flux:select.option>
                @endforeach
            </flux:select>
            <div x-show="$wire.pairing_target === 'new'">
                <flux:input wire:model="pairing_name" :label="__('Screen name')" :placeholder="__('Entrance')" maxlength="60" />
            </div>
            <p x-show="$wire.pairing_target !== 'new'" x-cloak class="text-xs text-amber-600 dark:text-amber-400">{{ __('The tablet currently used by this screen will be disconnected.') }}</p>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Pair') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="add-screen" class="w-full max-w-sm">
        <form wire:submit="addScreen" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Add a screen') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A secure link is created for this screen: open it on the device.') }}</flux:text>
            </div>
            <flux:input wire:model="new_screen_name" :label="__('Screen name')" :placeholder="__('Entrance')" maxlength="60" autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="screen-edit" variant="flyout" class="w-full max-w-md" wire:close="$set('editingScreenId', null)">
        @if ($editing = $this->editingScreen)
            <form wire:submit="saveScreen" class="flex h-full flex-col gap-6" x-data="{ confirmRotate: false, confirmDelete: false }">
                <div>
                    <flux:heading size="lg">{{ $editing->name }}</flux:heading>
                    <div class="mt-1"><x-admin.screen-status :screen="$editing" /></div>
                </div>

                <flux:input wire:model="s_name" :label="__('Name')" maxlength="60" required />

                <div>
                    <flux:label>{{ __('Screen link') }}</flux:label>
                    @if ($url = $editing->kioskUrl())
                        <flux:input :value="$url" readonly copyable class="mt-2" class:input="font-mono text-xs" />
                        <p class="mt-2 text-xs text-zinc-500">{{ __('This link gives access to the screen without an account: do not share it publicly.') }}</p>
                    @else
                        <p class="mt-1 text-sm text-zinc-500">{{ __('This screen’s link was created before the update. Regenerate it to be able to copy it here.') }}</p>
                    @endif
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="link" wire:click="openPairing({{ $editing->id }})">{{ __('Replace the tablet') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="arrow-path" x-show="! confirmRotate" x-on:click="confirmRotate = true">{{ __('Regenerate link') }}</flux:button>
                        <span x-show="confirmRotate" x-cloak class="flex items-center gap-2 text-xs text-amber-600 dark:text-amber-400">
                            {{ __('The device will be disconnected.') }}
                            <flux:button size="xs" variant="primary" wire:click="rotateScreen" x-on:click="confirmRotate = false">{{ __('Regenerate') }}</flux:button>
                        </span>
                    </div>
                </div>

                <flux:separator variant="subtle" />
                <flux:text class="-mb-2 text-xs">{{ __('“Room default” uses the settings shared by the room’s screens.') }}</flux:text>

                <flux:select wire:model="s_theme" :label="__('Theme')">
                    <flux:select.option value="">{{ __('Room default') }}</flux:select.option>
                    <flux:select.option value="dark">{{ __('Dark') }}</flux:select.option>
                    <flux:select.option value="light">{{ __('Light') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model="s_text_scale" :label="__('Text size')">
                    <flux:select.option value="">{{ __('Room default') }}</flux:select.option>
                    @foreach ([90 => __('Small'), 100 => __('Normal'), 115 => __('Large'), 130 => __('Extra large')] as $scale => $label)
                        <flux:select.option :value="$scale">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:select wire:model="s_night" :label="__('Night mode')">
                        <flux:select.option value="">{{ __('Room default') }}</flux:select.option>
                        <flux:select.option value="on">{{ __('Enabled for this screen') }}</flux:select.option>
                        <flux:select.option value="off">{{ __('Disabled for this screen') }}</flux:select.option>
                    </flux:select>
                    <div class="mt-3 grid grid-cols-2 gap-3" x-show="$wire.s_night === 'on'" x-cloak>
                        <flux:input wire:model="s_night_start" type="time" :aria-label="__('From')" />
                        <flux:input wire:model="s_night_end" type="time" :aria-label="__('Until')" />
                    </div>
                    <flux:error name="s_night_end" />
                </div>

                <div class="mt-auto flex items-center justify-between gap-2 border-t border-zinc-100 pt-4 dark:border-white/[0.06]">
                    <div>
                        <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600!" x-show="! confirmDelete" x-on:click="confirmDelete = true">{{ __('Remove') }}</flux:button>
                        <span x-show="confirmDelete" x-cloak class="flex items-center gap-2">
                            <flux:button size="sm" variant="danger" wire:click="deleteScreen">{{ __('Remove the screen') }}</flux:button>
                            <flux:button size="sm" variant="ghost" x-on:click="confirmDelete = false">{{ __('Cancel') }}</flux:button>
                        </span>
                    </div>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="admin-book" class="w-full max-w-md">
        <form wire:submit="createReservation" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New booking') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Today, in :room.', ['room' => $room->name]) }}</flux:text>
            </div>
            <flux:input wire:model="subject" :label="__('Subject')" required />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="start_time" type="time" :label="__('Start')" required />
                <flux:input wire:model="end_time" type="time" :label="__('End')" required />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Book') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="admin-edit" class="w-full max-w-md" wire:close="$set('editingEventId', null)">
        <form wire:submit="saveEditEvent" class="space-y-6">
            <flux:heading size="lg">{{ __('Edit meeting') }}</flux:heading>
            <flux:input wire:model="subject" :label="__('Subject')" required />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="start_time" type="time" :label="__('Start')" required />
                <flux:input wire:model="end_time" type="time" :label="__('End')" required />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-delete-event" class="w-full max-w-md" wire:close="$set('pendingDeleteEventId', null)">
        <flux:heading size="lg">{{ __('Cancel this booking?') }}</flux:heading>
        <flux:text class="mt-2">{{ __('The event will be removed from the room’s Microsoft 365 calendar.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Back') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="confirmDeleteEvent">{{ __('Cancel booking') }}</flux:button>
        </div>
    </flux:modal>

    <flux:modal name="confirm-delete-room" class="w-full max-w-md">
        <flux:heading size="lg">{{ __('Delete “:room”?', ['room' => $room->name]) }}</flux:heading>
        <flux:text class="mt-2">{{ __('This cannot be undone. The screen link will stop working.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteRoom">{{ __('Delete') }}</flux:button>
        </div>
    </flux:modal>
</div>
