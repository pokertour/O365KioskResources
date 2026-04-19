<?php

use App\Models\Room;
use App\Services\MicrosoftGraphService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Salle')] class extends Component {
    public Room $room;

    public string $graphError = '';

    /** @var list<array{id: string, subject: string, start: string, end: string}> */
    public array $events = [];

    public string $subject = '';

    public string $start_time = '';

    public string $end_time = '';

    public ?string $newKioskUrl = null;

    public ?string $pendingDeleteEventId = null;

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->newKioskUrl = session()->pull('new_kiosk_url');
        $this->refreshCalendar();
    }

    public function refreshCalendar(): void
    {
        $this->graphError = '';
        $graph = app(MicrosoftGraphService::class);

        if (! $graph->isConfigured()) {
            $this->events = [];
            $this->graphError = 'Microsoft 365 n’est pas configuré (variables AZURE_*).';

            return;
        }

        try {
            $start = CarbonImmutable::today()->startOfDay();
            $end = CarbonImmutable::today()->endOfDay();
            $this->events = $graph->listCalendarView($this->room->o365_resource_email, $start, $end);
        } catch (\Throwable $e) {
            Log::warning('Admin calendar fetch failed', ['exception' => $e]);
            $this->events = [];
            $this->graphError = $e->getMessage();
        }
    }

    public function createReservation(): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ]);

        $graph = app(MicrosoftGraphService::class);
        if (! $graph->isConfigured()) {
            Flux::toast(variant: 'danger', text: 'Microsoft 365 n’est pas configuré.');

            return;
        }

        $tz = config('app.timezone', 'UTC');
        $day = CarbonImmutable::today($tz);
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d').' '.$this->start_time, $tz);
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d').' '.$this->end_time, $tz);

        if (! $end->greaterThan($start)) {
            $this->addError('end_time', 'L’heure de fin doit être après le début.');

            return;
        }

        try {
            $graph->createCalendarEvent(
                $this->room->o365_resource_email,
                $this->subject,
                $start,
                $end,
            );
            Flux::toast(variant: 'success', text: 'Réservation créée.');
            $this->reset('subject', 'start_time', 'end_time');
            $this->modal('admin-book')->close();
            $this->refreshCalendar();
        } catch (\Throwable $e) {
            Log::warning('Admin book failed', ['exception' => $e]);
            Flux::toast(variant: 'danger', text: 'Échec : '.$e->getMessage());
        }
    }

    public function deleteEvent(string $eventId): void
    {
        $graph = app(MicrosoftGraphService::class);
        if (! $graph->isConfigured()) {
            Flux::toast(variant: 'danger', text: 'Microsoft 365 n’est pas configuré.');

            return;
        }

        try {
            $graph->deleteCalendarEvent($this->room->o365_resource_email, $eventId);
            Flux::toast(variant: 'success', text: 'Événement supprimé.');
            $this->refreshCalendar();
        } catch (\Throwable $e) {
            Log::warning('Admin delete event failed', ['exception' => $e]);
            Flux::toast(variant: 'danger', text: 'Échec : '.$e->getMessage());
        }
    }

    public function regenerateKioskToken(): void
    {
        [$plain, $hash] = Room::generateKioskCredentials();
        $this->room->kiosk_token_hash = $hash;
        $this->room->save();

        $url = route('kiosk.show', ['room' => $this->room, 'token' => $plain], absolute: true);
        session()->flash('new_kiosk_url', $url);

        Flux::toast(variant: 'success', text: 'Nouveau lien kiosque généré.');

        $this->redirect(route('admin.rooms.show', $this->room), navigate: true);
    }

    public function deleteRoom(): void
    {
        $this->room->delete();
        Flux::toast(variant: 'success', text: 'Salle supprimée.');

        $this->redirect(route('admin.rooms.index'), navigate: true);
    }

    public function openDeleteRoomModal(): void
    {
        $this->modal('confirm-delete-room')->show();
    }

    public function confirmDeleteRoom(): void
    {
        $this->modal('confirm-delete-room')->close();
        $this->deleteRoom();
    }

    public function openDeleteEventModal(string $eventId): void
    {
        $this->pendingDeleteEventId = $eventId;
        $this->modal('confirm-delete-event')->show();
    }

    public function cancelDeleteEventModal(): void
    {
        $this->pendingDeleteEventId = null;
    }

    public function confirmDeleteEvent(): void
    {
        if ($this->pendingDeleteEventId === null) {
            return;
        }

        $id = $this->pendingDeleteEventId;
        $this->pendingDeleteEventId = null;
        $this->modal('confirm-delete-event')->close();
        $this->deleteEvent($id);
    }

    public function openRegenerateTokenModal(): void
    {
        $this->modal('confirm-regenerate-token')->show();
    }

    public function confirmRegenerateKioskToken(): void
    {
        $this->modal('confirm-regenerate-token')->close();
        $this->regenerateKioskToken();
    }
}; ?>

<div class="admin-page flex flex-col gap-8" wire:poll.120s="refreshCalendar">
    <header class="admin-page-header flex flex-wrap items-start justify-between gap-6">
        <div class="min-w-0">
            <flux:link :href="route('admin.rooms.index')" class="text-sm text-emerald-400/90 hover:text-emerald-300" wire:navigate>← Salles</flux:link>
            <flux:heading size="xl" class="mt-2 text-balance text-zinc-50">{{ $room->name }}</flux:heading>
            <flux:text class="mt-2 text-sm text-zinc-500">{{ $room->slug }} · {{ $room->o365_resource_email }}</flux:text>
        </div>
        <div class="flex flex-shrink-0 flex-wrap gap-2">
            <flux:button variant="ghost" type="button" wire:click="refreshCalendar">Actualiser</flux:button>
            <flux:modal.trigger name="admin-book">
                <flux:button variant="primary" type="button">Ajouter une réservation</flux:button>
            </flux:modal.trigger>
            <flux:button variant="danger" type="button" wire:click="openDeleteRoomModal">
                Supprimer la salle
            </flux:button>
        </div>
    </header>

    @if ($newKioskUrl)
        <div class="admin-card-muted border-emerald-500/30 bg-emerald-950/20 text-emerald-100">
            <strong class="block text-sm font-semibold text-emerald-50">Nouvelle URL du kiosque</strong>
            <code class="mt-3 block break-all rounded-lg bg-black/30 px-3 py-2 font-mono text-xs text-emerald-200/95">{{ $newKioskUrl }}</code>
        </div>
    @endif

    @if ($graphError)
        <div class="admin-card-muted border-amber-500/35 bg-amber-950/25 text-amber-100">
            {{ $graphError }}
        </div>
    @endif

    <div class="admin-card">
        <flux:heading size="lg" class="mb-4 text-zinc-100">Planning du jour</flux:heading>
        @if (count($events) === 0)
            <flux:text class="text-zinc-500">Aucun événement.</flux:text>
        @else
            <ul class="admin-list-divide -mx-2 flex flex-col">
                @foreach ($events as $ev)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-2 py-4 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <span class="font-medium text-zinc-100">{{ $ev['subject'] ?: '(Sans titre)' }}</span>
                            <span class="mt-1 block text-sm text-zinc-500 sm:ms-2 sm:mt-0 sm:inline">
                                {{ \Carbon\CarbonImmutable::parse($ev['start'])->format('H:i') }}
                                —
                                {{ \Carbon\CarbonImmutable::parse($ev['end'])->format('H:i') }}
                            </span>
                        </div>
                        <flux:button
                            size="sm"
                            variant="danger"
                            type="button"
                            wire:click="openDeleteEventModal({{ \Illuminate\Support\Js::from($ev['id']) }})"
                        >
                            Supprimer
                        </flux:button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="admin-card">
        <flux:heading size="lg" class="mb-2 text-zinc-100">Lien kiosque</flux:heading>
        <flux:text class="mb-5 max-w-xl text-sm text-zinc-500">
            Le token est secret : régénérez-le si une tablette a été compromise.
        </flux:text>
        <flux:button type="button" variant="primary" wire:click="openRegenerateTokenModal">
            Régénérer le token kiosque
        </flux:button>
    </div>

    <flux:modal name="admin-book" class="max-w-lg">
        <form wire:submit="createReservation" class="space-y-4">
            <flux:heading size="lg">Nouvelle réservation</flux:heading>
            <flux:input wire:model="subject" label="Nom / objet" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="start_time" type="time" label="Début" required />
                <flux:input wire:model="end_time" type="time" label="Fin" required />
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Annuler</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Créer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-delete-room" class="max-w-md">
        <flux:heading size="lg">Supprimer cette salle ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-500">
            La salle, son planning et son lien kiosque seront supprimés définitivement. Cette action est irréversible.
        </flux:text>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Annuler</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="button" wire:click="confirmDeleteRoom">Supprimer</flux:button>
        </div>
    </flux:modal>

    <flux:modal name="confirm-delete-event" class="max-w-md" wire:close="cancelDeleteEventModal">
        <flux:heading size="lg">Supprimer la réservation ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-500">
            L’événement sera retiré du calendrier Microsoft 365 de cette salle.
        </flux:text>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Annuler</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="button" wire:click="confirmDeleteEvent">Supprimer</flux:button>
        </div>
    </flux:modal>

    <flux:modal name="confirm-regenerate-token" class="max-w-md">
        <flux:heading size="lg">Régénérer le token kiosque ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-500">
            Les anciennes URL du kiosque cesseront immédiatement de fonctionner. Une nouvelle URL vous sera affichée après confirmation.
        </flux:text>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Annuler</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="button" wire:click="confirmRegenerateKioskToken">Régénérer</flux:button>
        </div>
    </flux:modal>
</div>
