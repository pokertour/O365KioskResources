@props([
    'title' => null,
    'description' => null,
    'padded' => true,
])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/[0.08] dark:bg-zinc-900') }}>
    @if ($title || isset($headerActions))
        <div class="flex items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>
            {{ $headerActions ?? '' }}
        </div>
    @endif
    <div @class(['p-5' => $padded])>
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50/60 px-5 py-3 dark:border-white/[0.06] dark:bg-white/[0.02]">
            {{ $footer }}
        </div>
    @endisset
</section>
