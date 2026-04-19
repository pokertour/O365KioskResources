<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main
        class="relative min-h-dvh bg-gradient-to-b from-zinc-950 via-zinc-900 to-zinc-950 pb-16 pt-4 text-zinc-100 sm:pt-6"
    >
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
