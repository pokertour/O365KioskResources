<x-error-page code="401" icon="lock-closed" title="Sign-in required" message="You need to sign in to see this page.">
    <flux:button variant="primary" :href="route('login')" icon="arrow-right-end-on-rectangle">{{ __('Log in') }}</flux:button>
</x-error-page>
