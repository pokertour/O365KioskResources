<?php

use App\Models\ScreenPairing;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::kiosk')]
#[Title('Pair this screen')]
class extends Component {
    #[Locked]
    public ?int $pairingId = null;

    public function mount(): void
    {
        $this->resolvePairing();
    }

    /**
     * Reuses the pending pairing of this device (secret kept in its session) or starts a new one.
     */
    private function resolvePairing(): void
    {
        $secret = session('kiosk_pairing_secret');
        $pairing = is_string($secret)
            ? ScreenPairing::query()->where('device_hash', hash('sha256', $secret))->first()
            : null;

        if (! $pairing || ($pairing->room_id === null && $pairing->isExpired())) {
            $pairing?->delete();
            $secret = Str::random(40);
            session(['kiosk_pairing_secret' => $secret]);
            $pairing = ScreenPairing::query()->create([
                'code' => ScreenPairing::generateCode(),
                'device_hash' => hash('sha256', $secret),
                'expires_at' => now()->addMinutes(ScreenPairing::TTL_MINUTES),
            ]);
        }

        $this->pairingId = $pairing->id;
    }

    public function check(): void
    {
        // Only the device holding the secret of this pairing may receive the room link.
        $secret = session('kiosk_pairing_secret');
        $pairing = ScreenPairing::query()->find($this->pairingId);

        if (! $pairing || ! is_string($secret) || ! hash_equals($pairing->device_hash, hash('sha256', $secret))) {
            $this->resolvePairing();

            return;
        }

        if ($pairing->screen_id && ($url = $pairing->screen?->kioskUrl())) {
            $pairing->delete();
            session()->forget('kiosk_pairing_secret');
            $this->redirect($url);

            return;
        }

        if ($pairing->isExpired()) {
            $this->resolvePairing();
        }
    }

    public function pairing(): ?ScreenPairing
    {
        return ScreenPairing::query()->find($this->pairingId);
    }
}; ?>

@php
    $pairing = $this->pairing();
@endphp

<div class="flex h-dvh flex-col items-center justify-center gap-[4vmin] p-[6vmin] text-center" wire:poll.3s="check">
    <div class="flex items-center gap-3">
        <x-brand-mark size="lg" />
        <span class="text-2xl font-semibold tracking-tight">{{ $branding->displayName() }}</span>
    </div>

    <div>
        <p class="text-xl text-zinc-500 dark:text-zinc-400">{{ __('Pairing code') }}</p>
        <p class="mt-[2vmin] font-mono text-[min(16vmin,9rem)] leading-none font-semibold tracking-[0.15em] tabular-nums">
            {{ substr($pairing?->code ?? '------', 0, 3) }}<span class="text-zinc-300 dark:text-zinc-700"> </span>{{ substr($pairing?->code ?? '------', 3) }}
        </p>
    </div>

    <ol class="max-w-xl space-y-2 text-start text-lg text-zinc-600 dark:text-zinc-300">
        <li class="flex gap-3"><span class="font-semibold text-brand">1.</span>{{ __('In the administration, open the room, then the “Screen” tab.') }}</li>
        <li class="flex gap-3"><span class="font-semibold text-brand">2.</span>{{ __('Click “Enter a code” and type the code above.') }}</li>
        <li class="flex gap-3"><span class="font-semibold text-brand">3.</span>{{ __('This screen switches to the room automatically.') }}</li>
    </ol>

    @if ($pairing)
        <p class="text-sm text-zinc-400" x-data="{ left: {{ max(0, (int) now()->diffInSeconds($pairing->expires_at, false)) }} }" x-init="setInterval(() => left = Math.max(0, left - 1), 1000)">
            <span x-text="@js(__('A new code is generated in')) + ' ' + Math.ceil(left / 60) + ' min'"></span>
        </p>
    @endif
</div>
