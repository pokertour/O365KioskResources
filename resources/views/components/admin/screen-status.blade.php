@props([
    'screen',
])

@if ($screen->seen_at === null)
    <span class="inline-flex items-center gap-1.5 text-xs text-zinc-400"><span class="size-1.5 rounded-full bg-current"></span>{{ __('Never connected') }}</span>
@elseif ($screen->isOnline())
    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-available"><span class="size-1.5 rounded-full bg-current"></span>{{ __('Online') }}</span>
@else
    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400" title="{{ $screen->seen_at->diffForHumans() }}">
        <span class="size-1.5 rounded-full bg-current"></span>{{ __('Offline :since', ['since' => $screen->seen_at->diffForHumans(short: true)]) }}
    </span>
@endif
