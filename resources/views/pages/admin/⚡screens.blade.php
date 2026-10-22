<?php

use App\Models\AppSetting;
use App\Models\BrandingSetting;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Screens')] class extends Component {
    public int $kiosk_refresh_seconds = 30;

    /** @var list<int> */
    public array $quick_book_durations = [];

    public int $max_booking_minutes = 240;

    public bool $show_organizer = true;

    public string $kiosk_footer = '';

    public int $soon_minutes = 10;

    public bool $allow_end_extend = true;

    public int $text_scale = 100;

    public bool $night_mode = false;

    public string $night_start = '20:00';

    public string $night_end = '07:00';

    public string $agenda_view = 'timeline';

    public string $day_start = '08:00';

    public string $day_end = '19:00';

    public bool $show_qr_code = true;

    public function mount(): void
    {
        $s = AppSetting::current();
        $this->kiosk_refresh_seconds = $s->refreshSeconds();
        $this->quick_book_durations = $s->quickBookDurations();
        $this->max_booking_minutes = $s->max_booking_minutes;
        $this->show_organizer = $s->show_organizer;
        $this->kiosk_footer = (string) BrandingSetting::current()->kiosk_footer;
        $this->soon_minutes = $s->soonMinutes();
        $this->allow_end_extend = $s->allow_end_extend;
        $this->text_scale = $s->textScale();
        $this->night_mode = $s->night_mode;
        $this->night_start = $s->night_start;
        $this->night_end = $s->night_end;
        $this->agenda_view = $s->agendaView();
        $this->day_start = $s->day_start;
        $this->day_end = $s->day_end;
        $this->show_qr_code = $s->show_qr_code;
    }

    public function save(): void
    {
        $this->quick_book_durations = array_values(array_map('intval', $this->quick_book_durations));

        $this->validate([
            'kiosk_refresh_seconds' => ['required', 'integer', Rule::in(AppSetting::REFRESH_OPTIONS)],
            'quick_book_durations' => ['required', 'array', 'min:1', 'max:3'],
            'quick_book_durations.*' => ['integer', Rule::in(AppSetting::DURATION_OPTIONS)],
            'max_booking_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'show_organizer' => ['boolean'],
            'kiosk_footer' => ['nullable', 'string', 'max:160'],
            'soon_minutes' => ['required', 'integer', Rule::in(AppSetting::SOON_OPTIONS)],
            'allow_end_extend' => ['boolean'],
            'text_scale' => ['required', 'integer', Rule::in(AppSetting::TEXT_SCALES)],
            'night_mode' => ['boolean'],
            'night_start' => ['required', 'date_format:H:i'],
            'night_end' => ['required', 'date_format:H:i', 'different:night_start'],
            'agenda_view' => ['required', Rule::in(AppSetting::AGENDA_VIEWS)],
            'day_start' => ['required', 'date_format:H:i'],
            'day_end' => ['required', 'date_format:H:i', 'after:day_start'],
            'show_qr_code' => ['boolean'],
        ], [
            'quick_book_durations.min' => __('Choose at least one duration.'),
            'quick_book_durations.max' => __('Choose up to three durations.'),
        ], [
            'max_booking_minutes' => __('maximum duration'),
            'kiosk_footer' => __('message'),
            'night_start' => __('start'),
            'night_end' => __('end'),
        ]);

        sort($this->quick_book_durations);

        AppSetting::current()->fill([
            'kiosk_refresh_seconds' => $this->kiosk_refresh_seconds,
            'quick_book_durations' => $this->quick_book_durations,
            'max_booking_minutes' => $this->max_booking_minutes,
            'show_organizer' => $this->show_organizer,
            'soon_minutes' => $this->soon_minutes,
            'allow_end_extend' => $this->allow_end_extend,
            'text_scale' => $this->text_scale,
            'night_mode' => $this->night_mode,
            'night_start' => $this->night_start,
            'night_end' => $this->night_end,
            'agenda_view' => $this->agenda_view,
            'day_start' => $this->day_start,
            'day_end' => $this->day_end,
            'show_qr_code' => $this->show_qr_code,
        ])->save();

        // The footer is part of the screen's look: saving it through BrandingSetting reloads open screens.
        $branding = BrandingSetting::current();
        $branding->kiosk_footer = trim($this->kiosk_footer) ?: null;
        $branding->touch();
        $branding->save();

        \App\Models\ActivityLog::record('settings.updated', null, __('Screens'));
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }
}; ?>

<div>
    <x-admin.page-header :title="__('Screens')" :description="__('Behaviour of the screens installed in front of the rooms. Applies to every room.')" />

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <x-admin.card :title="__('Display')">
            <div class="space-y-6">
                <flux:radio.group wire:model="kiosk_refresh_seconds" :label="__('Refresh interval')" :description="__('How often the screens reload the room agenda.')" variant="segmented" class="sm:max-w-md">
                    @foreach (\App\Models\AppSetting::REFRESH_OPTIONS as $seconds)
                        <flux:radio :value="$seconds" :label="$seconds < 60 ? $seconds.' s' : ($seconds / 60).' min'" />
                    @endforeach
                </flux:radio.group>

                <flux:radio.group wire:model="text_scale" :label="__('Text size')" :description="__('Text adapts to the screen resolution automatically; adjust it for viewing distance.')" variant="segmented" class="sm:max-w-md">
                    @foreach ([90 => __('Small'), 100 => __('Normal'), 115 => __('Large'), 130 => __('Extra large')] as $scale => $label)
                        <flux:radio :value="$scale" :label="$label" />
                    @endforeach
                </flux:radio.group>

                <flux:radio.group wire:model="soon_minutes" :label="__('“Starting soon” warning')" :description="__('The screen turns to the “starting soon” colour before a meeting.')" variant="segmented" class="sm:max-w-md">
                    @foreach (\App\Models\AppSetting::SOON_OPTIONS as $minutes)
                        <flux:radio :value="$minutes" :label="$minutes === 0 ? __('Off') : $minutes.' min'" />
                    @endforeach
                </flux:radio.group>

                <flux:switch wire:model="show_organizer" :label="__('Show the organiser')" :description="__('Displays who booked the meeting, unless it is private or the room hides meeting subjects.')" />

                <flux:switch wire:model="show_qr_code" :label="__('QR code for phones')" :description="__('Shows a QR code opening the room agenda on a phone, where people can also book. The link expires after 30 minutes.')" />

                <flux:input wire:model="kiosk_footer" :label="__('Footer message')" :placeholder="__('Also book from Outlook or Teams')" maxlength="160" />
            </div>
        </x-admin.card>

        <x-admin.card :title="__('Agenda')" :description="__('Free slots can be tapped to book them.')">
            <div class="space-y-6">
                <div>
                    <flux:label>{{ __('Agenda layout') }}</flux:label>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            'timeline' => [__('Timeline'), __('Meetings sized by duration on an hour grid, with free slots and a “now” line.')],
                            'list' => [__('List'), __('Meetings one below the other, with free slots and past meetings folded.')],
                        ] as $value => [$label, $hint])
                            <label
                                class="flex cursor-pointer gap-4 rounded-xl border p-4 transition"
                                :class="$wire.agenda_view === @js($value) ? 'border-brand ring-2 ring-brand/20' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20'"
                            >
                                <input type="radio" wire:model="agenda_view" value="{{ $value }}" class="sr-only" />
                                {{-- Miniature of the layout --}}
                                <span class="relative h-16 w-14 shrink-0 overflow-hidden rounded-lg bg-zinc-100 p-1.5 dark:bg-white/5">
                                    @if ($value === 'timeline')
                                        <span class="absolute inset-x-1.5 top-2 h-5 rounded bg-brand/40"></span>
                                        <span class="absolute inset-x-1.5 top-8 h-2.5 rounded border border-dashed border-available/60"></span>
                                        <span class="absolute inset-x-1 top-[2.4rem] h-0.5 bg-busy"></span>
                                        <span class="absolute inset-x-1.5 top-11 h-3 rounded bg-brand/40"></span>
                                    @else
                                        @foreach ([0, 1, 2, 3] as $i)
                                            <span class="mb-1 flex gap-1"><span class="h-2.5 w-3 rounded-sm bg-zinc-300 dark:bg-white/20"></span><span class="h-2.5 flex-1 rounded-sm {{ $i === 1 ? 'border border-dashed border-available/60' : 'bg-brand/40' }}"></span></span>
                                        @endforeach
                                    @endif
                                </span>
                                <span>
                                    <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">{{ $hint }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div x-show="$wire.agenda_view === 'timeline'">
                    <flux:label>{{ __('Working hours') }}</flux:label>
                    <flux:description class="mt-1">{{ __('Range always shown on the timeline. It widens automatically for earlier or later meetings.') }}</flux:description>
                    <div class="mt-2 grid max-w-sm grid-cols-2 gap-4">
                        <flux:input wire:model="day_start" type="time" :aria-label="__('From')" />
                        <flux:input wire:model="day_end" type="time" :aria-label="__('Until')" />
                    </div>
                    <flux:error name="day_end" />
                </div>
            </div>
        </x-admin.card>

        <x-admin.card :title="__('Booking from the screen')" :description="__('Rooms where booking is enabled offer these quick durations when they are free.')">
            <div class="space-y-6">
                <div>
                    <flux:label>{{ __('Quick booking buttons') }}</flux:label>
                    <flux:description class="mt-1">{{ __('Up to three durations.') }}</flux:description>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (\App\Models\AppSetting::DURATION_OPTIONS as $minutes)
                            <label
                                class="cursor-pointer rounded-lg border px-3.5 py-2 text-sm font-medium tabular-nums transition"
                                :class="$wire.quick_book_durations.map(Number).includes({{ $minutes }}) ? 'border-brand bg-brand text-brand-foreground' : 'border-zinc-200 text-zinc-700 hover:border-zinc-300 dark:border-white/10 dark:text-zinc-300 dark:hover:border-white/20'"
                            >
                                <input type="checkbox" wire:model="quick_book_durations" value="{{ $minutes }}" class="sr-only" />
                                {{ fmt_duration($minutes) }}
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="quick_book_durations" />
                </div>

                <flux:select wire:model="max_booking_minutes" :label="__('Maximum booking duration')" class="sm:max-w-xs">
                    @foreach ([60, 120, 180, 240, 360, 480, 720] as $minutes)
                        <flux:select.option :value="$minutes">{{ fmt_duration($minutes) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:switch wire:model="allow_end_extend" :label="__('End or extend a meeting')" :description="__('During a meeting, the screen offers “End now” and “+15 min / +30 min” when the room stays free.')" />

                <p class="text-sm text-zinc-500">
                    {{ __('Booking from the screen can be turned on or off for each room from its page.') }}
                </p>
            </div>
        </x-admin.card>

        <x-admin.card :title="__('Night mode')" :description="__('Outside working hours the screens dim and only show the clock and the room status. A touch wakes them up for a minute.')">
            <div class="space-y-6">
                <flux:switch wire:model="night_mode" :label="__('Enable night mode')" />
                <div class="grid max-w-sm grid-cols-2 gap-4" x-show="$wire.night_mode" x-cloak>
                    <flux:input wire:model="night_start" type="time" :label="__('From')" />
                    <flux:input wire:model="night_end" type="time" :label="__('Until')" />
                </div>
            </div>
        </x-admin.card>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</div>
