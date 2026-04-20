<?php

use App\Models\Room;
use App\Services\MicrosoftGraphService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::kiosk')]
#[Title('Salle')]
class extends Component {
    public Room $room;

    public string $subject = '';

    public string $start_time = '';

    public string $end_time = '';

    public string $graphError = '';

    /** @var list<array{id: string, subject: string, start: string, end: string}> */
    public array $events = [];

    public function mount(Room $room, string $token): void
    {
        if (! $room->kioskTokenMatches($token)) {
            abort(404);
        }
        $this->room = $room;
        $this->refreshCalendar();
    }

    public function refreshCalendar(): void
    {
        $this->graphError = '';
        $graph = app(MicrosoftGraphService::class);

        if (! $graph->isConfigured()) {
            $this->events = [];
            $this->graphError = 'Microsoft 365 n’est pas configuré sur le serveur.';

            return;
        }

        try {
            $start = CarbonImmutable::today()->startOfDay();
            $end = CarbonImmutable::today()->endOfDay();
            $this->events = $graph->listCalendarView($this->room->o365_resource_email, $start, $end);
        } catch (\Throwable $e) {
            Log::warning('Kiosk calendar fetch failed', ['exception' => $e]);
            $this->events = [];
            $this->graphError = 'Impossible de charger l’agenda. Vérifiez la ressource et les droits Graph.';
        }
    }

    public function book(): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
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

        if ($end->lessThanOrEqualTo($start)) {
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
            $this->modal('kiosk-book')->close();
            $this->refreshCalendar();
        } catch (\Throwable $e) {
            Log::warning('Kiosk book failed', ['exception' => $e]);
            Flux::toast(variant: 'danger', text: 'Échec de la création : '.$e->getMessage());
        }
    }

    public function nowLabel(): string
    {
        return CarbonImmutable::now(config('app.timezone', 'UTC'))->locale('fr')->isoFormat('dddd D MMMM YYYY — HH:mm');
    }

    public function busyNow(): bool
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        foreach ($this->events as $ev) {
            try {
                $s = CarbonImmutable::parse($ev['start']);
                $e = CarbonImmutable::parse($ev['end']);
            } catch (\Throwable) {
                continue;
            }
            if ($now->greaterThanOrEqualTo($s) && $now->lessThan($e)) {
                return true;
            }
        }

        return false;
    }
}; ?>

<div class="flex min-h-screen flex-col" wire:poll.60s="refreshCalendar">
    <header class="border-b border-zinc-200 bg-white/90 px-6 py-5 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/80">
        <div class="mx-auto flex max-w-5xl flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">{{ $room->name }}</h1>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $this->nowLabel() }}</p>
            </div>
            <div
                class="rounded-xl border px-4 py-3 text-center text-sm font-medium {{ $this->busyNow() ? 'border-rose-500/50 bg-rose-950/40 text-rose-100' : 'border-emerald-500/50 bg-emerald-950/40 text-emerald-100' }}"
            >
                {{ $this->busyNow() ? 'Occupée' : 'Libre' }}
            </div>
        </div>
    </header>

    <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-6 py-8">
        @if ($graphError)
            <div class="rounded-xl border border-amber-500/45 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100">
                {{ $graphError }}
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-medium text-zinc-800 dark:text-zinc-200">Planning du jour</h2>
            <flux:modal.trigger name="kiosk-book">
                <flux:button variant="primary" class="min-h-12 min-w-[10rem] text-base">
                    Nouvelle réservation
                </flux:button>
            </flux:modal.trigger>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white/95 dark:border-zinc-800 dark:bg-zinc-900/50">
            @if (count($events) === 0)
                <p class="px-4 py-10 text-center text-zinc-600 dark:text-zinc-500">Aucun événement prévu aujourd’hui.</p>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach ($events as $ev)
                        <li class="flex flex-col gap-1 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $ev['subject'] ?: '(Sans titre)' }}</span>
                            <span class="shrink-0 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ \Carbon\CarbonImmutable::parse($ev['start'])->format('H:i') }}
                                —
                                {{ \Carbon\CarbonImmutable::parse($ev['end'])->format('H:i') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </main>

    <flux:modal name="kiosk-book" class="max-w-lg">
        <form wire:submit="book" class="space-y-4">
            <div>
                <flux:heading size="lg">Réserver la salle</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">Indiquez un libellé et le créneau (même journée).</flux:text>
            </div>
            <flux:input wire:model="subject" label="Nom / objet" required autocomplete="off" />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="start_time" type="time" label="Début" required />
                <flux:input wire:model="end_time" type="time" label="Fin" required />
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Annuler</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
