<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="relative isolate min-h-dvh overflow-clip bg-white! px-0! py-0! lg:my-2 lg:me-2 lg:rounded-2xl lg:border lg:border-zinc-200 lg:shadow-xs dark:bg-zinc-900! dark:lg:border-white/[0.06]">
        {{-- Soft brand-tinted light at the top of every page. --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 bg-[radial-gradient(60%_100%_at_50%_0%,color-mix(in_oklab,var(--color-brand)_9%,transparent),transparent)] dark:bg-[radial-gradient(60%_100%_at_50%_0%,color-mix(in_oklab,var(--color-brand)_14%,transparent),transparent)]"></div>
        <div class="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 lg:py-10">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
