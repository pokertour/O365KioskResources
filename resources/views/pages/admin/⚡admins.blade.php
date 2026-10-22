<?php

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Flux\Flux;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Administrators')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?int $userToDeleteId = null;

    #[Computed]
    public function users()
    {
        return User::query()->orderByDesc('is_admin')->orderBy('name')->get();
    }

    public function createAdmin(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', 'unique:users,email'],
            // With Microsoft sign-in enabled, the account may have no usable password.
            'password' => [AppSetting::current()->sso_enabled ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ], [], [
            'name' => __('name'),
            'email' => __('email'),
            'password' => __('password'),
        ]);

        User::query()->forceCreate([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password !== '' ? $this->password : Str::random(64),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
        ActivityLog::record('user.created', null, $this->email);

        $this->reset('name', 'email', 'password', 'password_confirmation');
        $this->modal('create-admin')->close();
        Flux::toast(variant: 'success', text: __('Administrator created.'));
        unset($this->users);
    }

    public function setAdmin(int $userId, bool $isAdmin): void
    {
        if ($userId === auth()->id()) {
            Flux::toast(variant: 'danger', text: __('You cannot change your own access.'));

            return;
        }

        $user = User::query()->findOrFail($userId);
        $user->forceFill(['is_admin' => $isAdmin])->save();
        ActivityLog::record('user.updated', null, $user->email.' → '.($isAdmin ? 'admin' : '—'));

        Flux::toast(variant: 'success', text: $isAdmin ? __('Administrator access granted.') : __('Administrator access removed.'));
        unset($this->users);
    }

    public function openDeleteUserModal(int $userId): void
    {
        if ($userId === auth()->id()) {
            return;
        }

        $this->userToDeleteId = $userId;
        $this->modal('confirm-delete-user')->show();
    }

    public function confirmDeleteUser(): void
    {
        if (! $this->userToDeleteId || $this->userToDeleteId === auth()->id()) {
            return;
        }

        $user = User::query()->findOrFail($this->userToDeleteId);
        ActivityLog::record('user.deleted', null, $user->email);
        $user->delete();
        $this->userToDeleteId = null;
        $this->modal('confirm-delete-user')->close();
        Flux::toast(variant: 'success', text: __('Account deleted.'));
        unset($this->users);
    }
}; ?>

<div>
    <x-admin.page-header icon="users" :title="__('Administrators')" :description="__('Accounts that can manage the rooms and the settings. There is no public sign-up.')">
        <x-slot:actions>
            <flux:modal.trigger name="create-admin">
                <flux:button variant="primary" icon="user-plus">{{ __('Add') }}</flux:button>
            </flux:modal.trigger>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card :padded="false">
        <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
            @foreach ($this->users as $user)
                @php($isSelf = $user->id === auth()->id())
                <li wire:key="user-{{ $user->id }}" class="flex items-center gap-4 px-5 py-4">
                    <flux:avatar :name="$user->name" :initials="$user->initials()" size="sm" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</span>
                            @if ($isSelf)
                                <span class="text-xs text-zinc-400">({{ __('you') }})</span>
                            @endif
                        </div>
                        <div class="truncate text-sm text-zinc-500">{{ $user->email }}</div>
                    </div>
                    <div class="hidden items-center gap-2 sm:flex">
                        @if ($user->microsoft_oid)
                            <flux:badge size="sm" color="sky">Microsoft</flux:badge>
                        @endif
                        @if ($user->two_factor_confirmed_at)
                            <flux:badge size="sm" color="zinc" icon="shield-check">2FA</flux:badge>
                        @endif
                        @if ($user->is_admin)
                            <flux:badge size="sm" color="green">Admin</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('No access') }}</flux:badge>
                        @endif
                    </div>
                    @unless ($isSelf)
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                            <flux:menu>
                                @if ($user->is_admin)
                                    <flux:menu.item icon="no-symbol" wire:click="setAdmin({{ $user->id }}, false)">{{ __('Remove access') }}</flux:menu.item>
                                @else
                                    <flux:menu.item icon="shield-check" wire:click="setAdmin({{ $user->id }}, true)">{{ __('Make administrator') }}</flux:menu.item>
                                @endif
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="openDeleteUserModal({{ $user->id }})">{{ __('Delete account') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @else
                        <span class="w-8"></span>
                    @endunless
                </li>
            @endforeach
        </ul>
    </x-admin.card>

    <p class="mt-4 text-xs text-zinc-500">
        {{ __('Recommended: each administrator enables two-factor authentication from') }}
        <a href="{{ route('security.edit') }}" wire:navigate class="font-medium text-brand hover:underline">{{ __('Security') }}</a>.
    </p>

    <flux:modal name="create-admin" variant="flyout" class="w-full max-w-md">
        <form wire:submit="createAdmin" class="flex h-full flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('New administrator') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Share the password securely; the person can change it from their account.') }}</flux:text>
            </div>
            <flux:input wire:model="name" :label="__('Name')" required autocomplete="off" />
            <flux:input wire:model="email" type="email" :label="__('Email')" required autocomplete="off" />
            @if (\App\Models\AppSetting::current()->sso_enabled)
                <flux:callout icon="information-circle" color="sky">
                    <flux:callout.text>{{ __('Microsoft sign-in is enabled: leave the password empty and the person signs in with their Microsoft 365 account (same e-mail address).') }}</flux:callout.text>
                </flux:callout>
            @endif
            <flux:input wire:model="password" type="password" :label="__('Password')" :required="! \App\Models\AppSetting::current()->sso_enabled" autocomplete="new-password" viewable />
            <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" :required="! \App\Models\AppSetting::current()->sso_enabled" autocomplete="new-password" viewable />
            <div class="mt-auto flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create account') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-delete-user" class="w-full max-w-md" wire:close="$set('userToDeleteId', null)">
        <flux:heading size="lg">{{ __('Delete this account?') }}</flux:heading>
        <flux:text class="mt-2">{{ __('This cannot be undone.') }}</flux:text>
        <div class="mt-6 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="confirmDeleteUser">{{ __('Delete') }}</flux:button>
        </div>
    </flux:modal>
</div>
