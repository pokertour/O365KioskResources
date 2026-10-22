<x-error-page code="503" icon="cog-6-tooth" title="Maintenance in progress" message="The application is being updated and will be back in a few minutes." auto-retry>
    <flux:button variant="primary" icon="arrow-path" x-data x-on:click="window.location.reload()">{{ __('Try again') }}</flux:button>
</x-error-page>
