<x-error-page code="419" icon="clock" title="Page expired" message="The page stayed open too long and your session expired. Reload it and try again.">
    <flux:button variant="primary" icon="arrow-path" x-data x-on:click="window.location.reload()">{{ __('Reload the page') }}</flux:button>
    <flux:button variant="ghost" :href="url('/')" icon="home">{{ __('Back to home') }}</flux:button>
</x-error-page>
