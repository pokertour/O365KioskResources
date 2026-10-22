<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="min-h-dvh bg-white! px-0! py-0! lg:my-2 lg:me-2 lg:rounded-2xl lg:border lg:border-zinc-200 lg:shadow-xs dark:bg-zinc-900! dark:lg:border-white/[0.06]">
        <div class="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 lg:py-10">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
