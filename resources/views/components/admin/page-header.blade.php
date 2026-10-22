@props([
    'title',
    'description' => null,
    'back' => null,
    'backLabel' => null,
])

<header {{ $attributes->class('mb-8 flex flex-col gap-5 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end sm:justify-between dark:border-white/[0.07]') }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm text-zinc-500 transition hover:text-zinc-900 dark:hover:text-white">
                <flux:icon.arrow-left variant="micro" />
                {{ $backLabel }}
            </a>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-balance text-zinc-900 dark:text-white">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm text-pretty text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
