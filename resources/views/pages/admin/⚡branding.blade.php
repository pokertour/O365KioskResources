<?php

use App\Models\BrandingSetting;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Branding')] class extends Component {
    use WithFileUploads;

    public const PRESETS = ['#2563eb', '#4f46e5', '#0f766e', '#0369a1', '#7c3aed', '#be123c', '#c2410c', '#18181b'];

    public string $site_name = '';

    /** Logo for light backgrounds. */
    public $logo = null;

    /** Logo for dark backgrounds (optional: the light one is used when missing). */
    public $logo_dark = null;

    /** Variant whose removal is being confirmed. */
    public string $removing = 'light';

    public string $primary_color = '';

    public string $available_color = '';

    public string $busy_color = '';

    public string $soon_color = '';

    public string $kiosk_theme = 'dark';

    public function mount(): void
    {
        $b = BrandingSetting::current();
        $this->site_name = (string) $b->site_name;
        $this->primary_color = $b->primary_color;
        $this->available_color = $b->available_color;
        $this->busy_color = $b->busy_color;
        $this->soon_color = $b->soon_color;
        $this->kiosk_theme = $b->kioskTheme();
    }

    public function save(): void
    {
        $hex = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];

        $this->validate([
            'site_name' => ['nullable', 'string', 'max:60'],
            // SVG is accepted: the file is served from a private disk with a sandboxing CSP.
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml,image/svg'],
            'logo_dark' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml,image/svg'],
            'primary_color' => $hex,
            'available_color' => $hex,
            'busy_color' => $hex,
            'soon_color' => $hex,
            'kiosk_theme' => ['required', Rule::in(BrandingSetting::THEMES)],
        ], [
            'regex' => __('Invalid colour (#RRGGBB format).'),
        ], [
            'site_name' => __('display name'),
            'logo' => __('logo'),
            'logo_dark' => __('logo'),
        ]);

        $b = BrandingSetting::current();
        $b->fill([
            'site_name' => trim($this->site_name) ?: null,
            'primary_color' => strtolower($this->primary_color),
            'available_color' => strtolower($this->available_color),
            'busy_color' => strtolower($this->busy_color),
            'soon_color' => strtolower($this->soon_color),
            'kiosk_theme' => $this->kiosk_theme,
        ]);

        if ($this->logo) {
            $b->deleteStoredLogo('light');
            $b->logo_path = $this->logo->store('branding', BrandingSetting::LOGO_DISK);
        }
        if ($this->logo_dark) {
            $b->deleteStoredLogo('dark');
            $b->logo_dark_path = $this->logo_dark->store('branding', BrandingSetting::LOGO_DISK);
        }

        $b->save();
        $this->logo = null;
        $this->logo_dark = null;

        \App\Models\ActivityLog::record('settings.updated', null, __('Branding'));
        Flux::toast(variant: 'success', text: __('Branding saved.'));
        // Full navigation so the new palette is injected in the page head.
        $this->redirect(route('admin.branding'), navigate: true);
    }

    public function resetColors(): void
    {
        $this->primary_color = BrandingSetting::DEFAULTS['primary_color'];
        $this->available_color = BrandingSetting::DEFAULTS['available_color'];
        $this->busy_color = BrandingSetting::DEFAULTS['busy_color'];
        $this->soon_color = BrandingSetting::DEFAULTS['soon_color'];
    }

    public function confirmRemoveLogo(string $variant): void
    {
        $this->removing = in_array($variant, BrandingSetting::LOGO_VARIANTS, true) ? $variant : 'light';
        $this->modal('confirm-remove-logo')->show();
    }

    public function removeLogo(): void
    {
        $b = BrandingSetting::current();
        $b->deleteStoredLogo($this->removing);
        $b->save();
        $this->modal('confirm-remove-logo')->close();

        Flux::toast(variant: 'success', text: __('Logo removed.'));
        $this->redirect(route('admin.branding'), navigate: true);
    }
}; ?>

@php
    // Livewire cannot preview an SVG before it is saved: the stored logo stays shown until then.
    $preview = fn ($upload, string $variant) => $upload && ! str_contains((string) $upload->getMimeType(), 'svg') ? $upload->temporaryUrl() : $branding->logoUrl($variant);
    $logoPreviews = ['light' => $preview($logo, 'light'), 'dark' => $preview($logo_dark, 'dark')];
    // What the screens will show on each background (a missing variant falls back to the other).
    $logoFor = ['light' => $logoPreviews['light'] ?? $logoPreviews['dark'], 'dark' => $logoPreviews['dark'] ?? $logoPreviews['light']];
@endphp

<div>
    <x-admin.page-header icon="swatch" :title="__('Branding')" :description="__('Customise the name, logo and colours. They apply to the administration and to every screen.')" />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
        <div class="flex flex-col gap-6">
            <x-admin.card icon="identification" :title="__('Identity')">
                <div class="space-y-6">
                    <flux:input wire:model="site_name" :label="__('Display name')" :placeholder="config('app.name')" :description="__('Shown in the menu and at the top of the screens.')" maxlength="60" />

                    <div>
                        <flux:label>{{ __('Logo') }}</flux:label>
                        <flux:description class="mt-1">{{ __('One logo for light backgrounds and one for dark backgrounds, e.g. a white version. With a single logo, it is used everywhere.') }}</flux:description>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                'light' => ['logo', __('On a light background'), 'bg-white ring-zinc-200', 'text-zinc-300'],
                                'dark' => ['logo_dark', __('On a dark background'), 'bg-zinc-950 ring-white/10', 'text-zinc-700'],
                            ] as $variant => [$field, $label, $surface, $placeholder])
                                @php
                                    $upload = $this->{$field};
                                    $current = $logoPreviews[$variant];
                                    $fallback = $current ? null : $logoFor[$variant];
                                @endphp
                                <div wire:key="logo-{{ $variant }}" class="rounded-xl border border-zinc-200 p-3 dark:border-white/10">
                                    <div class="flex h-24 items-center justify-center rounded-lg p-3 ring-1 ring-inset {{ $surface }}">
                                        @if ($current)
                                            <img src="{{ $current }}" alt="" class="max-h-full max-w-full object-contain" />
                                        @elseif ($fallback)
                                            {{-- Shows the other logo, dimmed: that is what this background gets today. --}}
                                            <img src="{{ $fallback }}" alt="" class="max-h-full max-w-full object-contain opacity-40" />
                                        @else
                                            <flux:icon.photo class="size-7 {{ $placeholder }}" />
                                        @endif
                                    </div>
                                    <div class="mt-3 flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</p>
                                            <p class="truncate text-xs text-zinc-500">
                                                @if ($upload)
                                                    {{ __(':file — save to apply', ['file' => $upload->getClientOriginalName()]) }}
                                                @elseif ($current)
                                                    {{ __('Custom logo') }}
                                                @elseif ($fallback)
                                                    {{ __('Uses the other logo') }}
                                                @else
                                                    {{ __('Default icon') }}
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 text-sm font-medium text-zinc-800 shadow-xs transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10">
                                                <flux:icon.arrow-up-tray variant="micro" />
                                                {{ $current ? __('Replace') : __('Choose') }}
                                                <input type="file" wire:model="{{ $field }}" accept=".png,.jpg,.jpeg,.webp,.svg" class="sr-only" />
                                            </label>
                                            @if ($branding->hasLogo($variant) && ! $upload)
                                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmRemoveLogo('{{ $variant }}')" :aria-label="__('Remove')" />
                                            @endif
                                        </div>
                                    </div>
                                    <span wire:loading wire:target="{{ $field }}" class="mt-1 block text-xs text-zinc-500">{{ __('Uploading…') }}</span>
                                    <flux:error :name="$field" />
                                </div>
                            @endforeach
                        </div>
                        <flux:description class="mt-2">{{ __('PNG, JPG, WebP or SVG, 2 MB max. A logo on a transparent background looks best on the screens.') }}</flux:description>
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card icon="swatch" :title="__('Colours')">
                <x-slot:headerActions>
                    <flux:button size="sm" variant="ghost" wire:click="resetColors">{{ __('Reset') }}</flux:button>
                </x-slot:headerActions>

                <div class="space-y-6">
                    <div>
                        <flux:label>{{ __('Primary colour') }}</flux:label>
                        <flux:description class="mt-1">{{ __('Buttons, links and active items.') }}</flux:description>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @foreach ($this::PRESETS as $preset)
                                <button
                                    type="button"
                                    x-on:click="$wire.primary_color = @js($preset)"
                                    class="size-8 rounded-full ring-2 ring-offset-2 ring-offset-white transition hover:scale-110 dark:ring-offset-zinc-900"
                                    :class="$wire.primary_color.toLowerCase() === @js($preset) ? 'ring-zinc-900 dark:ring-white' : 'ring-transparent'"
                                    style="background-color: {{ $preset }}"
                                    aria-label="{{ $preset }}"
                                ></button>
                            @endforeach
                            <span class="mx-1 h-6 w-px bg-zinc-200 dark:bg-white/10"></span>
                            <label class="relative size-8 cursor-pointer overflow-hidden rounded-full border border-zinc-300 dark:border-white/20" title="{{ __('Custom colour') }}">
                                <span class="absolute inset-0 bg-[conic-gradient(red,yellow,lime,aqua,blue,magenta,red)]"></span>
                                <input type="color" wire:model="primary_color" class="absolute inset-0 size-full cursor-pointer opacity-0" />
                            </label>
                            <flux:input wire:model="primary_color" size="sm" class="w-28" class:input="font-mono uppercase" maxlength="7" />
                        </div>
                        <flux:error name="primary_color" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-3">
                        @foreach ([['available_color', __('Room available')], ['soon_color', __('Starting soon')], ['busy_color', __('Room busy')]] as [$prop, $label])
                            <div>
                                <flux:label>{{ $label }}</flux:label>
                                <div class="mt-2 flex items-center gap-2">
                                    <label class="relative size-9 shrink-0 cursor-pointer overflow-hidden rounded-lg border border-zinc-200 dark:border-white/10" x-bind:style="{ backgroundColor: $wire.{{ $prop }} }">
                                        <input type="color" wire:model="{{ $prop }}" class="absolute inset-0 size-full cursor-pointer opacity-0" />
                                    </label>
                                    <flux:input wire:model="{{ $prop }}" size="sm" class:input="font-mono uppercase" maxlength="7" />
                                </div>
                                <flux:error :name="$prop" />
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card icon="device-tablet" :title="__('Room screens')">
                <div class="space-y-6">
                    <div>
                        <flux:label>{{ __('Theme') }}</flux:label>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            @foreach (['dark' => [__('Dark'), __('Recommended for wall-mounted screens.')], 'light' => [__('Light'), __('For very bright rooms.')]] as $value => [$label, $hint])
                                <label @class([
                                    'flex cursor-pointer flex-col gap-3 rounded-xl border p-3 transition',
                                ]) :class="$wire.kiosk_theme === @js($value) ? 'border-brand ring-2 ring-brand/20' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20'">
                                    <input type="radio" wire:model="kiosk_theme" value="{{ $value }}" class="sr-only" />
                                    <span @class(['flex h-14 gap-1.5 rounded-lg p-1.5', 'bg-zinc-950' => $value === 'dark', 'bg-zinc-100 ring-1 ring-zinc-200' => $value === 'light'])>
                                        <span class="w-1/2 rounded-md" x-bind:style="{ backgroundColor: $wire.available_color }"></span>
                                        <span @class(['flex-1 rounded-md', 'bg-zinc-800' => $value === 'dark', 'bg-white' => $value === 'light'])></span>
                                    </span>
                                    <span>
                                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</span>
                                        <span class="block text-xs text-zinc-500">{{ $hint }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-admin.card>
        </div>

        {{-- Live preview --}}
        <div class="lg:sticky lg:top-6 lg:self-start">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Screen preview') }}</h2>
                <span class="text-xs text-zinc-500">{{ __('Live') }}</span>
            </div>

            <div
                class="overflow-hidden rounded-2xl border border-zinc-200 shadow-lg dark:border-white/10"
                x-data="{ step: 0, get state() { return ['free', 'soon', 'busy'][this.step] }, get busy() { return this.state === 'busy' } }"
                x-init="setInterval(() => step = (step + 1) % 3, 3000)"
                x-effect="
                    const hex = ({ free: $wire.available_color, soon: $wire.soon_color, busy: $wire.busy_color })[state] || '#000000';
                    const [r, g, b] = [1, 3, 5].map(i => { const c = parseInt(hex.substr(i, 2), 16) / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 });
                    $el.style.setProperty('--preview-fg', 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.36 ? '#0a0a0a' : '#ffffff');
                "
            >
                <div
                    class="flex aspect-[4/3] flex-col gap-3 p-4 transition-colors"
                    :class="$wire.kiosk_theme === 'dark' ? 'bg-zinc-950 text-white' : 'bg-zinc-50 text-zinc-900'"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            @if ($logoFor['light'])
                                <img src="{{ $logoFor['light'] }}" alt="" class="size-5 object-contain" x-show="$wire.kiosk_theme === 'light'" />
                                <img src="{{ $logoFor['dark'] }}" alt="" class="size-5 object-contain" x-show="$wire.kiosk_theme === 'dark'" />
                            @else
                                <span class="flex size-5 items-center justify-center rounded" x-bind:style="{ backgroundColor: $wire.primary_color }"></span>
                            @endif
                            <span class="text-[11px] font-semibold" x-text="$wire.site_name || @js(config('app.name'))"></span>
                        </div>
                        <span class="text-sm font-semibold tabular-nums">09:41</span>
                    </div>
                    <div class="grid flex-1 grid-cols-[1.15fr_1fr] gap-2">
                        <div class="flex flex-col rounded-xl p-3 text-(--preview-fg) transition-colors duration-700" x-bind:style="{ backgroundColor: ({ free: $wire.available_color, soon: $wire.soon_color, busy: $wire.busy_color })[state] }">
                            <span class="text-sm font-semibold leading-tight">{{ __('Horizon room') }}</span>
                            <span class="mt-1.5 w-fit rounded-full bg-black/15 px-1.5 py-0.5 text-[8px] font-semibold uppercase tracking-wider" x-text="({ free: @js(__('Available')), soon: @js(__('Starting soon')), busy: @js(__('Busy')) })[state]"></span>
                            <span class="mt-auto text-[10px] font-semibold" x-text="({ free: @js(__('Free until :time', ['time' => '10:30'])), soon: @js(trans_choice('Starts in :count minute|Starts in :count minutes', 5)), busy: @js(__('Board meeting')) })[state]"></span>
                            <div class="mt-1.5 flex gap-1" x-show="! busy">
                                <span class="h-3 w-6 rounded bg-white/90"></span>
                                <span class="h-3 w-6 rounded bg-white/90"></span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5 rounded-xl p-2.5" :class="$wire.kiosk_theme === 'dark' ? 'bg-zinc-900' : 'bg-white ring-1 ring-zinc-200'">
                            <span class="text-[9px] font-semibold">{{ __('Today') }}</span>
                            @foreach (['10:30', '14:00', '16:30'] as $i => $t)
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[8px] tabular-nums opacity-70">{{ $t }}</span>
                                    <span class="h-4 w-0.5 rounded-full" x-bind:style="{ backgroundColor: {{ $i === 0 ? '$wire.primary_color' : "'rgba(128,128,128,.3)'" }} }"></span>
                                    <span class="h-1.5 flex-1 rounded-full bg-current opacity-15"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <flux:button type="submit" variant="primary" class="flex-1">{{ __('Save') }}</flux:button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">{{ __('Open screens update automatically within a minute.') }}</p>
        </div>
    </form>

    <flux:modal name="confirm-remove-logo" class="w-full max-w-md">
        <flux:heading size="lg">{{ $removing === 'dark' ? __('Remove the logo for dark backgrounds?') : __('Remove the logo for light backgrounds?') }}</flux:heading>
        <flux:text class="mt-2">{{ $branding->hasLogo($removing === 'dark' ? 'light' : 'dark') ? __('The other logo will be used instead.') : __('The default icon will be used instead.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="removeLogo">{{ __('Remove') }}</flux:button>
        </div>
    </flux:modal>
</div>
