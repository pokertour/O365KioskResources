{{--
    ⌘K / Ctrl+K palette (shortcut bound once in resources/js/app.js): jump to any admin page or room. Items are rendered server-side (so they
    keep their Flux icons) and filtered in place by Alpine, accent- and case-insensitively.
--}}
@php
    $groups = [
        __('Pages') => [
            ['building-office-2', __('Rooms'), route('admin.rooms.index'), null],
            ['chart-bar', __('Statistics'), route('admin.stats'), null],
            ['megaphone', __('Announcements'), route('admin.announcements'), null],
            ['clock', __('Activity'), route('admin.activity'), null],
        ],
        __('Settings') => [
            ['adjustments-horizontal', __('General'), route('admin.general'), __('Language, time zone')],
            ['tv', __('Screens'), route('admin.screens'), __('Refresh, night mode, booking')],
            ['swatch', __('Branding'), route('admin.branding'), __('Logo, colours')],
            ['bell', __('Notifications'), route('admin.notifications'), __('E-mail, Teams')],
            ['cloud', 'Microsoft 365', route('admin.microsoft'), __('Connection, SSO, permissions')],
            ['users', __('Administrators'), route('admin.users.index'), null],
        ],
        __('Rooms') => \App\Models\Room::query()->orderBy('name')->get(['id', 'name', 'slug', 'location', 'o365_resource_email'])
            ->map(fn ($room) => ['building-office', $room->name, route('admin.rooms.show', $room), $room->location ?: $room->o365_resource_email])
            ->all(),
        __('My account') => [
            ['user-circle', __('Profile'), route('profile.edit'), null],
            ['shield-check', __('Security'), route('security.edit'), __('Password, two-factor authentication')],
        ],
    ];
@endphp

<div
    class="contents"
    data-command-palette
    x-data="{
        open: false,
        q: '',
        active: 0,
        norm(s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() },
        matches(text) {
            const words = this.norm(this.q).trim().split(/\s+/).filter(Boolean)
            return words.every(w => this.norm(text).includes(w))
        },
        visible() { return [...this.$refs.list.querySelectorAll('[data-cmd]')].filter(el => this.matches(el.dataset.text)) },
        isActive(el) { return this.visible()[this.active] === el },
        show() { this.open = true; this.q = ''; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        move(step) {
            const items = this.visible()
            if (! items.length) return
            this.active = (this.active + step + items.length) % items.length
            items[this.active].scrollIntoView({ block: 'nearest' })
        },
        run() { this.visible()[this.active]?.click() },
    }"
    x-on:toggle-command-palette.window="open ? open = false : show()"
    x-on:open-command-palette.window="show()"
    x-effect="q; active = 0"
>
    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.prevent.stop="open = false"
        class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Search') }}"
    >
        <div x-show="open" x-transition.opacity.duration.150ms class="absolute inset-0 bg-zinc-950/40 backdrop-blur-[2px]" x-on:click="open = false"></div>

        <div
            x-show="open"
            x-transition:enter="transition duration-150 ease-out"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-trap.noscroll="open"
            class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl shadow-zinc-950/20 dark:border-white/10 dark:bg-zinc-900"
        >
            <div class="flex items-center gap-3 border-b border-zinc-100 px-4 dark:border-white/[0.07]">
                <flux:icon.magnifying-glass class="size-5 shrink-0 text-zinc-400" />
                <input
                    x-ref="input"
                    x-model="q"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    x-on:keydown.arrow-up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="run()"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="{{ __('Search a page, a room, a setting…') }}"
                    class="h-14 w-full bg-transparent text-base text-zinc-900 placeholder:text-zinc-400 focus:outline-none dark:text-white"
                />
                <kbd class="hidden shrink-0 rounded-md border border-zinc-200 px-1.5 py-0.5 font-sans text-[11px] text-zinc-400 sm:block dark:border-white/10">Esc</kbd>
            </div>

            <div x-ref="list" class="max-h-[min(60vh,26rem)] overflow-y-auto overscroll-contain p-2">
                @foreach ($groups as $group => $items)
                    @continue(count($items) === 0)
                    <div x-show="[...$el.querySelectorAll('[data-cmd]')].some(el => matches(el.dataset.text))" class="pb-1">
                        <div class="px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase dark:text-zinc-500">{{ $group }}</div>
                        @foreach ($items as [$icon, $label, $url, $hint])
                            <a
                                href="{{ $url }}"
                                wire:navigate
                                data-cmd
                                data-text="{{ $label }} {{ $hint }} {{ $group }}"
                                x-show="matches($el.dataset.text)"
                                x-on:click="open = false"
                                x-on:mousemove="active = visible().indexOf($el)"
                                x-bind:class="isActive($el) ? 'bg-zinc-100 text-zinc-900 dark:bg-white/[0.07] dark:text-white' : 'text-zinc-700 dark:text-zinc-300'"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm"
                            >
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200/70 ring-inset dark:bg-white/[0.06] dark:text-zinc-400 dark:ring-white/[0.06]"
                                      x-bind:class="isActive($el.closest('[data-cmd]')) && '!bg-brand/10 !text-brand !ring-brand/20'">
                                    <flux:icon :name="$icon" variant="mini" class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ $label }}</span>
                                    @if ($hint)
                                        <span class="block truncate text-xs text-zinc-500">{{ $hint }}</span>
                                    @endif
                                </span>
                                <flux:icon.arrow-turn-down-left variant="micro" class="shrink-0 text-zinc-400" x-show="isActive($el.closest('[data-cmd]'))" />
                            </a>
                        @endforeach
                    </div>
                @endforeach

                <div x-show="visible().length === 0" class="px-3 py-10 text-center text-sm text-zinc-500">
                    {{ __('No results') }}
                </div>
            </div>

            <div class="flex items-center gap-4 border-t border-zinc-100 bg-zinc-50/70 px-4 py-2 text-[11px] text-zinc-500 dark:border-white/[0.07] dark:bg-white/[0.02]">
                <span class="inline-flex items-center gap-1"><kbd class="rounded border border-zinc-200 bg-white px-1 font-sans dark:border-white/10 dark:bg-white/5">↑</kbd><kbd class="rounded border border-zinc-200 bg-white px-1 font-sans dark:border-white/10 dark:bg-white/5">↓</kbd> {{ __('to navigate') }}</span>
                <span class="inline-flex items-center gap-1"><kbd class="rounded border border-zinc-200 bg-white px-1 font-sans dark:border-white/10 dark:bg-white/5">↵</kbd> {{ __('to open') }}</span>
            </div>
        </div>
    </div>
</div>
