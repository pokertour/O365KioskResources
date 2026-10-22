@props([
    'compact' => false,
])

<flux:dropdown :position="$compact ? 'bottom' : 'top'" :align="$compact ? 'end' : 'start'" {{ $attributes }}>
    @if ($compact)
        <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />
    @else
        <flux:sidebar.profile
            :name="auth()->user()->name"
            :initials="auth()->user()->initials()"
            icon:trailing="chevrons-up-down"
            data-test="sidebar-menu-button"
        />
    @endif

    <flux:menu class="min-w-64">
        <div class="flex items-center gap-3 px-2 py-1.5">
            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" size="sm" />
            <div class="min-w-0 text-sm">
                <div class="truncate font-medium text-zinc-900 dark:text-white">{{ auth()->user()->name }}</div>
                <div class="truncate text-zinc-500">{{ auth()->user()->email }}</div>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>{{ __('Profile') }}</flux:menu.item>
        <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>{{ __('Security') }}</flux:menu.item>
        <flux:menu.item :href="route('appearance.edit')" icon="sun" wire:navigate>{{ __('Display') }}</flux:menu.item>
        <flux:menu.separator />
        {{-- One small form per language: Flux menu items only forward clicks on their own items,
             and they reserve the `value` attribute, hence the hidden field. --}}
        @foreach (\App\Models\AppSetting::LOCALES as $code => $label)
            <form method="POST" action="{{ route('locale.update') }}" class="w-full">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}" />
                <flux:menu.item
                    as="button"
                    type="submit"
                    :icon="app()->getLocale() === $code ? 'check' : 'language'"
                    :lang="$code"
                    @class(['w-full', 'font-semibold' => app()->getLocale() === $code])
                >
                    {{ $label }}
                </flux:menu.item>
            </form>
        @endforeach
        <flux:menu.separator />
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
