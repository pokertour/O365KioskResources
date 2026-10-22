<?php

use App\Models\AppSetting;
use App\Services\RoomSchedule;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('General')] class extends Component {
    public string $locale = 'fr';

    public string $timezone = 'Europe/Paris';

    public string $time_format = '24h';

    public function mount(): void
    {
        $s = AppSetting::current();
        $this->locale = $s->locale();
        $this->timezone = $s->timezone();
        $this->time_format = $s->time_format;
    }

    /**
     * Time zones grouped by region, labelled with their current UTC offset.
     *
     * @return array<string, array<string, string>>
     */
    #[Computed(persist: true)]
    public function timezones(): array
    {
        $groups = [];
        foreach (timezone_identifiers_list() as $tz) {
            [$region] = explode('/', $tz, 2) + [1 => null];
            $offset = CarbonImmutable::now($tz)->format('P');
            $groups[$region][$tz] = '(UTC'.$offset.') '.str_replace(['_', '/'], [' ', ' / '], $tz);
        }

        return $groups;
    }

    public function save(): void
    {
        $this->validate([
            'locale' => ['required', Rule::in(array_keys(AppSetting::LOCALES))],
            'timezone' => ['required', 'timezone:all'],
            'time_format' => ['required', Rule::in(AppSetting::TIME_FORMATS)],
        ]);

        $settings = AppSetting::current();
        $timezoneChanged = $settings->timezone !== $this->timezone;
        $schedule = app(RoomSchedule::class);
        if ($timezoneChanged) {
            $schedule->forgetAll();
        }
        $settings->fill([
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'time_format' => $this->time_format,
        ])->save();

        // Agendas were fetched with the previous time zone: drop them for the new local day too.
        if ($timezoneChanged) {
            $schedule->forgetAll();
        }

        \App\Models\ActivityLog::record('settings.updated', null, __('General'));
        Flux::toast(variant: 'success', text: __('Settings saved.'));
        $this->redirect(route('admin.general'), navigate: true);
    }
}; ?>

<div>
    <x-admin.page-header :title="__('General')" :description="__('Language, time zone and time format used across the administration and the room screens.')" />

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <x-admin.card :title="__('Language')" :description="__('Default language of the screens and of the administration. Each administrator can choose their own from their menu.')">
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\Models\AppSetting::LOCALES as $code => $label)
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 transition"
                        :class="$wire.locale === @js($code) ? 'border-brand ring-2 ring-brand/20' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20'"
                    >
                        <input type="radio" wire:model="locale" value="{{ $code }}" class="sr-only" />
                        <span class="flex size-9 items-center justify-center rounded-lg bg-zinc-100 text-xs font-bold uppercase text-zinc-700 dark:bg-white/10 dark:text-zinc-200">{{ $code }}</span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-white" lang="{{ $code }}">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </x-admin.card>

        <x-admin.card :title="__('Date and time')">
            <div class="space-y-6">
                <flux:select wire:model="timezone" :label="__('Time zone')" :description="__('Used to display the agendas and create bookings.')">
                    @foreach ($this->timezones as $region => $zones)
                        <optgroup label="{{ $region }}">
                            @foreach ($zones as $tz => $label)
                                <option value="{{ $tz }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </flux:select>

                <div>
                    <flux:label>{{ __('Time format') }}</flux:label>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:max-w-sm">
                        @foreach (['24h' => '14:30', '12h' => '2:30 PM'] as $value => $sample)
                            <label
                                class="flex cursor-pointer flex-col rounded-xl border px-4 py-3 transition"
                                :class="$wire.time_format === @js($value) ? 'border-brand ring-2 ring-brand/20' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20'"
                            >
                                <input type="radio" wire:model="time_format" value="{{ $value }}" class="sr-only" />
                                <span class="text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $sample }}</span>
                                <span class="text-xs text-zinc-500">{{ $value === '24h' ? __('24-hour') : __('12-hour') }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <p class="text-sm text-zinc-500">
                    {{ __('Current time in this time zone:') }}
                    <span class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ fmt_day(app(RoomSchedule::class)->now()) }}, {{ fmt_time(app(RoomSchedule::class)->now()) }}</span>
                </p>
            </div>
        </x-admin.card>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</div>
