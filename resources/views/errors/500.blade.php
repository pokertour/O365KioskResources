<x-error-page code="500" icon="wrench-screwdriver" title="Something went wrong" message="An unexpected error occurred on our side. It has been logged; please try again in a moment." auto-retry>
    <flux:button variant="primary" icon="arrow-path" x-data x-on:click="window.location.reload()">{{ __('Try again') }}</flux:button>
    <flux:button variant="ghost" :href="url('/')" icon="home">{{ __('Back to home') }}</flux:button>
</x-error-page>
