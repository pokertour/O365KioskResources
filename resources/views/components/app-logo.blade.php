@props([
    'sidebar' => false,
])

<a {{ $attributes->merge(['class' => 'group flex min-w-0 items-center gap-3 rounded-lg px-1 py-1']) }}>
    <x-brand-mark size="sm" />
    <span class="truncate text-sm font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $branding->displayName() }}</span>
</a>
