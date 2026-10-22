<x-error-page code="429" icon="hand-raised" title="Too many requests" message="Too many attempts in a short time. Wait a moment, then try again." auto-retry>
    <flux:button variant="primary" icon="arrow-path" x-data x-on:click="window.location.reload()">{{ __('Try again') }}</flux:button>
</x-error-page>
