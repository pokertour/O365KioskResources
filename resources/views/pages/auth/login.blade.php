<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Sign in to the administration')" :description="__('Access restricted to administrators.')" />

        @php
            $loginSettings = \App\Models\AppSetting::current();
            $ssoAvailable = $loginSettings->sso_enabled && app(\App\Services\MicrosoftGraphService::class)->isConfigured();
            $passwordAllowed = $loginSettings->passwordLoginAllowed() || ! $ssoAvailable;
        @endphp

        @if ($ssoAvailable)
            <flux:button :href="route('auth.microsoft')" class="w-full" data-test="microsoft-login">
                <svg viewBox="0 0 21 21" class="size-4" aria-hidden="true"><path fill="#f25022" d="M1 1h9v9H1z"/><path fill="#7fba00" d="M11 1h9v9h-9z"/><path fill="#00a4ef" d="M1 11h9v9H1z"/><path fill="#ffb900" d="M11 11h9v9h-9z"/></svg>
                {{ __('Sign in with Microsoft') }}
            </flux:button>
            @if ($passwordAllowed)
                <div class="flex items-center gap-3 text-xs text-zinc-400"><span class="h-px flex-1 bg-zinc-200 dark:bg-white/10"></span>{{ __('or') }}<span class="h-px flex-1 bg-zinc-200 dark:bg-white/10"></span></div>
            @endif
        @endif

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @error('email')
            @unless ($passwordAllowed)
                <flux:callout icon="exclamation-triangle" color="red"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
            @endunless
        @enderror

        @if ($passwordAllowed)
        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                :placeholder="__('you@company.com')"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute end-0 top-0 text-sm" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>
        @endif

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
