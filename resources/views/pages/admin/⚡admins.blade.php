<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Administrateurs')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('email')->get();
    }

    public function createAdmin(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->reset('name', 'email', 'password', 'password_confirmation');
        Flux::toast(variant: 'success', text: 'Administrateur créé.');
        unset($this->users);
    }

    public function revokeAdmin(int $userId): void
    {
        if ($userId === auth()->id()) {
            Flux::toast(variant: 'danger', text: 'Vous ne pouvez pas retirer votre propre accès.');

            return;
        }

        $user = User::query()->findOrFail($userId);
        $user->is_admin = false;
        $user->save();

        Flux::toast(variant: 'success', text: 'Droits administrateur retirés.');
        unset($this->users);
    }

    public function promoteAdmin(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $user->is_admin = true;
        $user->save();

        Flux::toast(variant: 'success', text: 'Administrateur ajouté.');
        unset($this->users);
    }
}; ?>

<div class="admin-page flex flex-col gap-10">
    <header class="admin-page-header">
        <flux:heading size="xl" class="text-balance text-zinc-50">Administrateurs</flux:heading>
        <flux:text class="mt-2 max-w-2xl text-pretty text-sm leading-relaxed text-zinc-400 sm:text-base">
            Seuls les administrateurs peuvent gérer les salles et les réservations. Il n’y a pas d’inscription publique : créez les comptes ici.
        </flux:text>
    </header>

    <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
        <form wire:submit="createAdmin" class="admin-card flex flex-col gap-5">
            <flux:heading size="lg" class="text-zinc-100">Ajouter un administrateur</flux:heading>
            <div class="space-y-4">
                <flux:input wire:model="name" label="Nom" required autocomplete="name" />
                <flux:input wire:model="email" type="email" label="E-mail" required autocomplete="off" />
                <flux:input wire:model="password" type="password" label="Mot de passe" required autocomplete="new-password" viewable />
                <flux:input wire:model="password_confirmation" type="password" label="Confirmation" required autocomplete="new-password" viewable />
            </div>
            <flux:button variant="primary" type="submit" class="w-full sm:w-auto">Créer le compte</flux:button>
        </form>

        <div class="admin-card flex flex-col">
            <flux:heading size="lg" class="mb-4 text-zinc-100">Comptes</flux:heading>
            <ul class="admin-list-divide -mx-2 flex flex-col">
                @foreach ($this->users as $user)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-2 py-4 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <div class="font-medium text-zinc-100">{{ $user->name }}</div>
                            <div class="truncate text-sm text-zinc-500">{{ $user->email }}</div>
                            @if ($user->is_admin)
                                <span class="mt-1 inline-block rounded-md bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-300">Admin</span>
                            @else
                                <span class="mt-1 inline-block rounded-md bg-zinc-600/20 px-2 py-0.5 text-xs font-medium text-zinc-400">Utilisateur</span>
                            @endif
                        </div>
                        <div class="flex flex-shrink-0 flex-wrap gap-2">
                            @if ($user->is_admin)
                                @if ($user->id === auth()->id())
                                    <flux:button size="sm" variant="ghost" type="button" disabled>Retirer admin</flux:button>
                                @else
                                    <flux:button size="sm" variant="ghost" type="button" wire:click="revokeAdmin({{ $user->id }})">
                                        Retirer admin
                                    </flux:button>
                                @endif
                            @else
                                <flux:button size="sm" variant="primary" type="button" wire:click="promoteAdmin({{ $user->id }})">
                                    Promouvoir admin
                                </flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
