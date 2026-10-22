@props([
    'title',
    'description' => null,
    'icon' => null,
    'back' => null,
    'backLabel' => null,
])

<header {{ $attributes->class('mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="group mb-4 inline-flex items-center gap-1.5 rounded-full py-1 pe-3 ps-2 text-sm text-zinc-500 ring-1 ring-zinc-200 transition hover:bg-zinc-50 hover:text-zinc-900 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white">
                <flux:icon.arrow-left variant="micro" class="transition group-hover:-translate-x-0.5" />
                {{ $backLabel }}
            </a>
        @endif
        <div class="flex items-center gap-4">
            @if ($icon)
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-linear-to-b from-brand/15 to-brand/5 text-brand ring-1 ring-brand/20 ring-inset dark:from-brand/25 dark:to-brand/10 dark:text-[color-mix(in_oklab,var(--color-brand)_55%,white)] dark:ring-brand/30">
                    <flux:icon :name="$icon" class="size-5" />
                </span>
            @endif
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-balance text-zinc-900 dark:text-white">{{ $title }}</h1>
                @if ($description)
                    <p class="mt-1 max-w-2xl text-sm text-pretty text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>
        </div>
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
