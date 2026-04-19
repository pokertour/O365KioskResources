<?php

use App\Models\Room;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Salles')] class extends Component {
    public string $name = '';

    public string $slug = '';

    public string $o365_resource_email = '';

    public ?string $newKioskUrl = null;

    public Collection $rooms;

    public ?int $pendingDeleteRoomId = null;

    public function mount(): void
    {
        $this->newKioskUrl = session()->pull('new_kiosk_url');
        $this->loadRooms();
    }

    public function loadRooms(): void
    {
        $this->rooms = Room::query()->orderBy('name')->get();
    }

    public function createRoom(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'o365_resource_email' => ['required', 'email', 'max:255'],
        ]);

        $slugTrim = trim($this->slug);
        if ($slugTrim !== '') {
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugTrim)) {
                $this->addError('slug', 'Utilisez des minuscules, chiffres et tirets uniquement.');

                return;
            }
            if (Room::query()->where('slug', $slugTrim)->exists()) {
                $this->addError('slug', 'Cet identifiant est déjà utilisé.');

                return;
            }
        }

        [$plain, $hash] = Room::generateKioskCredentials();

        $room = Room::query()->create([
            'name' => $this->name,
            'slug' => $slugTrim,
            'o365_resource_email' => $this->o365_resource_email,
            'kiosk_token_hash' => $hash,
        ]);

        $url = route('kiosk.show', ['room' => $room, 'token' => $plain], absolute: true);
        session()->flash('new_kiosk_url', $url);

        Flux::toast(variant: 'success', text: 'Salle créée. Copiez l’URL du kiosque affichée sur cette page.');

        $this->redirect(route('admin.rooms.index'), navigate: true);
    }

    public function deleteRoom(int $id): void
    {
        Room::query()->whereKey($id)->delete();
        Flux::toast(variant: 'success', text: 'Salle supprimée.');
        $this->loadRooms();
    }

    public function openDeleteRoomModal(int $id): void
    {
        $this->pendingDeleteRoomId = $id;
        $this->modal('confirm-delete-room')->show();
    }

    public function cancelDeleteRoomModal(): void
    {
        $this->pendingDeleteRoomId = null;
    }

    public function confirmDeleteRoom(): void
    {
        if ($this->pendingDeleteRoomId === null) {
            return;
        }

        $id = $this->pendingDeleteRoomId;
        $this->pendingDeleteRoomId = null;
        $this->modal('confirm-delete-room')->close();
        $this->deleteRoom($id);
    }
}; ?>

<div class="admin-page flex flex-col gap-10">
    <header class="admin-page-header">
        <flux:heading size="xl" class="text-balance text-zinc-50">Salles de réunion</flux:heading>
        <flux:text class="mt-2 max-w-2xl text-pretty text-sm leading-relaxed text-zinc-400 sm:text-base">
            Chaque salle correspond à une ressource boîte aux lettres Microsoft 365 (adresse SMTP de la salle).
        </flux:text>
    </header>

    @if ($newKioskUrl)
        <div class="admin-card-muted border-emerald-500/30 bg-emerald-950/20 text-emerald-100">
            <strong class="block text-sm font-semibold text-emerald-50">URL du kiosque (à conserver)</strong>
            <code class="mt-3 block break-all rounded-lg bg-black/30 px-3 py-2 font-mono text-xs text-emerald-200/95">{{ $newKioskUrl }}</code>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
        <form wire:submit="createRoom" class="admin-card flex flex-col gap-5">
            <flux:heading size="lg" class="text-zinc-100">Ajouter une salle</flux:heading>
            <div class="space-y-4">
                <flux:input wire:model="name" label="Nom" required />
                <flux:input wire:model="slug" label="Identifiant URL (optionnel)" placeholder="ex. salle-a" />
                <flux:input wire:model="o365_resource_email" type="email" label="E-mail ressource Microsoft 365" required />
            </div>
            <flux:button variant="primary" type="submit" class="w-full sm:w-auto">Créer</flux:button>
        </form>

        <div class="admin-card flex min-h-[280px] flex-col">
            <flux:heading size="lg" class="mb-4 text-zinc-100">Salles</flux:heading>
            @if ($rooms->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700/80 bg-zinc-950/40 px-6 py-12 text-center">
                    <flux:text class="text-zinc-500">Aucune salle pour le moment.</flux:text>
                    <flux:text class="mt-2 max-w-xs text-sm text-zinc-600">Créez une salle à gauche pour générer le lien kiosque.</flux:text>
                </div>
            @else
                <ul class="admin-list-divide -mx-2 flex flex-col">
                    @foreach ($rooms as $room)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-2 py-4 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <flux:link
                                    :href="route('admin.rooms.show', $room)"
                                    class="font-medium text-zinc-100 hover:text-emerald-300"
                                    wire:navigate
                                >
                                    {{ $room->name }}
                                </flux:link>
                                <div class="mt-0.5 truncate text-xs text-zinc-500">{{ $room->slug }} · {{ $room->o365_resource_email }}</div>
                            </div>
                            <flux:button
                                size="sm"
                                variant="danger"
                                type="button"
                                wire:click="openDeleteRoomModal({{ $room->id }})"
                            >
                                Supprimer
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <flux:modal name="confirm-delete-room" class="max-w-md" wire:close="cancelDeleteRoomModal">
        <flux:heading size="lg">Supprimer cette salle ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-500">
            La salle et son lien kiosque seront supprimés définitivement. Cette action est irréversible.
        </flux:text>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" type="button" wire:click="cancelDeleteRoomModal">Annuler</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="button" wire:click="confirmDeleteRoom">
                Supprimer
            </flux:button>
        </div>
    </flux:modal>
</div>
