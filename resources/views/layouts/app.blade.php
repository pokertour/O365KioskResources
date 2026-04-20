<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main
        class="relative min-h-dvh bg-gradient-to-b from-zinc-50 via-zinc-100 to-zinc-50 pb-16 pt-4 text-zinc-900 dark:from-zinc-950 dark:via-zinc-900 dark:to-zinc-950 dark:text-zinc-100 sm:pt-6"
    >
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
