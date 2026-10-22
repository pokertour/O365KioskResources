@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'padded' => true,
])

<section {{ $attributes->merge(['id' => $title ? 'section-'.\Illuminate\Support\Str::slug($title) : null])->class('overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-xs shadow-zinc-950/[0.03] dark:border-white/[0.07] dark:bg-white/[0.025] dark:shadow-none') }}>
    @if ($title || isset($headerActions))
        <div class="flex items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-white/[0.06]">
            <div class="flex min-w-0 items-start gap-3">
                @if ($icon)
                    <span class="mt-px flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200/70 ring-inset dark:bg-white/[0.06] dark:text-zinc-300 dark:ring-white/[0.06]">
                        <flux:icon :name="$icon" variant="mini" class="size-4" />
                    </span>
                @endif
                <div @class(['min-w-0', 'pt-1.5' => $icon && ! $description])>
                    @if ($title)
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
                    @endif
                </div>
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
