<?php

use App\Models\MicrosoftSetting;
use App\Services\MicrosoftGraphService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Microsoft 365')] class extends Component {
    public string $tenant_id = '';

    public string $client_id = '';

    public string $client_secret = '';

    public bool $hasSecret = false;

    public bool $sso_enabled = false;

    public bool $password_login_enabled = true;

    /** null = not tested yet, true = OK, string = error message */
    public bool|string|null $testResult = null;

    public function mount(): void
    {
        $m = MicrosoftSetting::current();
        $this->tenant_id = (string) $m->tenant_id;
        $this->client_id = (string) $m->client_id;
        $this->hasSecret = filled($m->client_secret);

        $s = \App\Models\AppSetting::current();
        $this->sso_enabled = $s->sso_enabled;
        $this->password_login_enabled = $s->password_login_enabled;
    }

    public function saveSso(): void
    {
        $this->validate(['sso_enabled' => ['boolean'], 'password_login_enabled' => ['boolean']]);

        // Never lock everyone out: passwords can only be turned off by someone already signed in with Microsoft.
        if ($this->sso_enabled && ! $this->password_login_enabled && ! auth()->user()->microsoft_oid) {
            $this->password_login_enabled = true;
            $this->addError('password_login_enabled', __('Sign in once with Microsoft before disabling passwords, so you keep access.'));

            return;
        }

        $s = \App\Models\AppSetting::current();
        $s->sso_enabled = $this->sso_enabled;
        $s->password_login_enabled = $this->password_login_enabled || ! $this->sso_enabled;
        $s->save();
        $this->password_login_enabled = $s->password_login_enabled;
        \App\Models\ActivityLog::record('settings.updated', null, __('Microsoft sign-in'));

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    #[Computed]
    public function configured(): bool
    {
        return app(MicrosoftGraphService::class)->isConfigured();
    }

    public function save(): void
    {
        $guid = ['required', 'string', 'regex:/^[0-9a-fA-F-]{36}$|^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255'];

        $this->validate([
            'tenant_id' => $guid,
            'client_id' => ['required', 'uuid'],
            'client_secret' => [$this->hasSecret ? 'nullable' : 'required', 'string', 'max:2048'],
        ], [
            'tenant_id.regex' => __('Enter the tenant ID (GUID) or its domain (contoso.onmicrosoft.com).'),
            'client_id.uuid' => __('The application ID must be a GUID.'),
        ], [
            'tenant_id' => __('tenant ID'),
            'client_id' => __('application ID'),
            'client_secret' => __('client secret'),
        ]);

        $m = MicrosoftSetting::current();
        $m->tenant_id = trim($this->tenant_id);
        $m->client_id = trim($this->client_id);
        if (trim($this->client_secret) !== '') {
            $m->client_secret = trim($this->client_secret);
        }
        $m->save();

        \App\Models\ActivityLog::record('settings.updated', null, 'Microsoft 365');
        $graph = app(MicrosoftGraphService::class);
        $graph->forgetToken();

        $this->client_secret = '';
        $this->hasSecret = true;
        unset($this->configured);

        $this->test();
    }

    /**
     * Application permissions granted to the app (from the access token), or null if unknown.
     *
     * @return list<string>|null
     */
    #[Computed]
    public function grantedRoles(): ?array
    {
        if (! $this->configured) {
            return null;
        }

        try {
            return app(MicrosoftGraphService::class)->grantedRoles();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetches a new token so permissions granted in Entra since the last one are taken into account.
     */
    public function checkPermissions(): void
    {
        app(MicrosoftGraphService::class)->forgetToken();
        unset($this->grantedRoles);

        if ($this->grantedRoles === null) {
            Flux::toast(variant: 'danger', text: __('The connection failed.'));

            return;
        }

        $missing = array_keys(array_filter(MicrosoftGraphService::APPLICATION_PERMISSIONS, fn ($required, $name) => ! in_array($name, $this->grantedRoles, true), ARRAY_FILTER_USE_BOTH));
        Flux::toast(
            variant: $missing === [] ? 'success' : 'warning',
            text: $missing === [] ? __('All permissions are granted.') : __('Missing: :list', ['list' => implode(', ', $missing)]),
        );
    }

    public function test(): void
    {
        try {
            app(MicrosoftGraphService::class)->testConnection();
            unset($this->grantedRoles);
            $this->testResult = true;
            Flux::toast(variant: 'success', text: __('Connected to Microsoft 365.'));
        } catch (\Throwable $e) {
            $this->testResult = $e->getMessage();
            Flux::toast(variant: 'danger', text: __('The connection failed.'));
        }
    }
}; ?>

<div>
    <x-admin.page-header title="Microsoft 365" :description="__('Connection to Microsoft Graph to read and book the room calendars.')">
        <x-slot:meta>
            <div class="mt-3">
                @if ($testResult === true)
                    <flux:badge color="green" icon="check-circle" size="sm">{{ __('Connected') }}</flux:badge>
                @elseif (is_string($testResult))
                    <flux:badge color="red" icon="x-circle" size="sm">{{ __('Connection failed') }}</flux:badge>
                @elseif ($this->configured)
                    <flux:badge color="zinc" icon="cloud" size="sm">{{ __('Configured') }}</flux:badge>
                @else
                    <flux:badge color="amber" icon="exclamation-triangle" size="sm">{{ __('Not configured') }}</flux:badge>
                @endif
            </div>
        </x-slot:meta>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <form wire:submit="save">
            <x-admin.card :title="__('Entra ID app registration')" :description="__('Values available in the Microsoft Entra admin center, under “App registrations”.')">
                @if (is_string($testResult))
                    <flux:callout icon="x-circle" color="red" class="mb-6">
                        <flux:callout.heading>{{ __('Microsoft refused the connection') }}</flux:callout.heading>
                        <flux:callout.text class="break-words">{{ $testResult }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="space-y-5">
                    <flux:input wire:model="tenant_id" :label="__('Tenant ID (Directory ID)')" placeholder="00000000-0000-0000-0000-000000000000" class:input="font-mono text-sm" autocomplete="off" required />
                    <flux:input wire:model="client_id" :label="__('Application ID (Client ID)')" placeholder="00000000-0000-0000-0000-000000000000" class:input="font-mono text-sm" autocomplete="off" required />
                    <flux:input
                        wire:model="client_secret"
                        type="password"
                        :label="__('Client secret')"
                        :placeholder="$hasSecret ? '•••••••••••• ('.__('saved').')' : ''"
                        :description="$hasSecret ? __('Leave empty to keep the current secret. It is stored encrypted and never shown again.') : __('Stored encrypted, never shown again.')"
                        autocomplete="new-password"
                        viewable
                    />
                </div>

                <x-slot:footer>
                    @if ($this->configured)
                        <flux:button type="button" variant="ghost" size="sm" icon="signal" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                            {{ __('Test connection') }}
                        </flux:button>
                    @endif
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Save and test') }}</flux:button>
                </x-slot:footer>
            </x-admin.card>
        </form>

        <form wire:submit="saveSso" class="lg:col-start-1">
            <x-admin.card :title="__('Sign in with Microsoft')" :description="__('Administrators sign in with their Microsoft 365 account, with your organisation’s MFA and policies.')">
                <div class="space-y-5">
                    <div class="rounded-lg bg-zinc-50 p-4 text-sm dark:bg-white/[0.03]">
                        <p class="font-medium text-zinc-900 dark:text-white">{{ __('In the app registration › Authentication, add a “Web” redirect URI:') }}</p>
                        <flux:input :value="route('auth.microsoft.callback')" readonly copyable class="mt-2" class:input="font-mono text-xs" />
                        <p class="mt-2 text-xs text-zinc-500">{{ __('The delegated permission User.Read (present by default) is enough.') }}</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-white/10">
                        <p class="font-medium text-zinc-900 dark:text-white">{{ __('Who can sign in?') }}</p>
                        <p class="mt-1 text-zinc-500">{{ __('Only the accounts listed in Administrators, with administrator access, matched by e-mail address. Everyone else in your tenant is refused.') }}</p>
                        <p class="mt-2 text-zinc-500">{{ __('Recommended: in Microsoft Entra › Enterprise applications › this app › Properties, set “Assignment required” to Yes, then assign your administrators (or their group) under “Users and groups”. Others are then stopped by Microsoft itself. This does not affect the calendar access of the screens.') }}</p>
                    </div>
                    <flux:switch wire:model="sso_enabled" :label="__('Enable “Sign in with Microsoft”')" :disabled="! $this->configured" />
                    <div x-show="$wire.sso_enabled" x-cloak>
                        <flux:switch wire:model="password_login_enabled" :label="__('Also allow passwords')" :description="__('Turn off to require Microsoft sign-in for everyone.')" />
                        <flux:error name="password_login_enabled" />
                    </div>
                </div>
                <x-slot:footer>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                </x-slot:footer>
            </x-admin.card>
        </form>

        <aside class="space-y-4 text-sm lg:col-start-2 lg:row-start-1 lg:row-span-2">
            @php
                $roles = $this->grantedRoles;
                $permissions = [
                    ['Calendars.ReadWrite', __('Application'), __('Required'), __('Read and book the room calendars.')],
                    ['Place.Read.All', __('Application'), __('Optional'), __('Import the rooms of the tenant.')],
                    ['User.Read', __('Delegated'), __('Microsoft sign-in only'), __('Sign administrators in with Microsoft. Present by default.')],
                ];
                $entraUrl = filled($client_id)
                    ? 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/'.rawurlencode($client_id)
                    : 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade';
            @endphp
            <x-admin.card :title="__('API permissions')" :padded="false">
                <x-slot:headerActions>
                    @if ($this->configured)
                        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="checkPermissions" wire:loading.attr="disabled" wire:target="checkPermissions">{{ __('Check') }}</flux:button>
                    @endif
                </x-slot:headerActions>

                <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach ($permissions as [$name, $type, $need, $purpose])
                        @php
                            $isApp = $type === __('Application');
                            $granted = $isApp && $roles !== null ? in_array($name, $roles, true) : null;
                        @endphp
                        <li class="flex items-start gap-3 px-5 py-3">
                            @if ($granted === true)
                                <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0 text-available" />
                            @elseif ($granted === false)
                                <flux:icon.x-circle variant="mini" @class(['mt-0.5 shrink-0', 'text-red-500' => $need === __('Required'), 'text-amber-500' => $need !== __('Required')]) />
                            @else
                                <flux:icon.minus-circle variant="mini" class="mt-0.5 shrink-0 text-zinc-300 dark:text-zinc-600" />
                            @endif
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                                <p class="text-xs text-zinc-500">{{ $type }} · {{ $need }}</p>
                                <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">{{ $purpose }}</p>
                                @if ($granted === false)
                                    <p class="mt-1 text-xs font-medium {{ $need === __('Required') ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">{{ __('Not granted') }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            <x-admin.card :title="__('How to grant them')">
                <ol class="space-y-3 text-zinc-600 dark:text-zinc-400">
                    @foreach ([
                        __('Open the app registration in Microsoft Entra, then “API permissions”.'),
                        __('“Add a permission” › Microsoft Graph › “Application permissions”, tick the permissions above, then “Add permissions”.'),
                        __('Click “Grant admin consent for” your organisation: the status must turn green.'),
                        __('Come back here and click “Check”. It can take a few minutes for Microsoft to apply the change.'),
                    ] as $i => $step)
                        <li class="flex gap-3"><span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200">{{ $i + 1 }}</span><span>{{ $step }}</span></li>
                    @endforeach
                </ol>
                <flux:button size="sm" class="mt-4" icon:trailing="arrow-top-right-on-square" :href="$entraUrl" target="_blank" rel="noopener noreferrer">{{ __('Open in Microsoft Entra') }}</flux:button>
                <p class="mt-4 text-xs text-zinc-500">
                    {{ __('Tip: restrict the application to the room mailboxes only with an') }}
                    <a href="https://learn.microsoft.com/graph/auth-limit-mailbox-access" target="_blank" rel="noopener noreferrer" class="font-medium text-brand underline-offset-2 hover:underline">{{ __('application access policy') }}</a>.
                </p>
            </x-admin.card>
        </aside>
    </div>
</div>
