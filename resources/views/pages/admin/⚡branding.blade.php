<?php

use App\Models\BrandingSetting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Logo & titre')] class extends Component {
    use WithFileUploads;

    public string $site_name = '';

    public $logo = null;

    public function mount(): void
    {
        $b = BrandingSetting::current();
        $this->site_name = (string) ($b->site_name ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'site_name' => ['nullable', 'string', 'max:80'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif,webp,svg'],
        ]);

        $b = BrandingSetting::current();
        $trim = trim($this->site_name);
        $b->site_name = $trim === '' ? null : $trim;

        if ($this->logo) {
            if ($b->logo_path) {
                Storage::disk('public')->delete($b->logo_path);
            }
            $b->logo_path = $this->logo->store('branding', 'public');
        }

        $b->save();
        $this->logo = null;

        Flux::toast(variant: 'success', text: 'Identité visuelle enregistrée.');
        $this->redirect(route('admin.branding'), navigate: true);
    }

    public function removeLogo(): void
    {
        $b = BrandingSetting::current();
        $b->deleteStoredLogo();
        $b->save();

        Flux::toast(variant: 'success', text: 'Logo réinitialisé.');
        $this->redirect(route('admin.branding'), navigate: true);
    }

    public function openRemoveLogoModal(): void
    {
        $this->modal('confirm-remove-logo')->show();
    }

    public function confirmRemoveLogo(): void
    {
        $this->modal('confirm-remove-logo')->close();
        $this->removeLogo();
    }
}; ?>

<div class="admin-page flex flex-col gap-10">
    <header class="admin-page-header">
        <flux:heading size="xl" class="text-balance text-zinc-50">Logo &amp; titre</flux:heading>
        <flux:text class="mt-2 max-w-2xl text-pretty text-sm leading-relaxed text-zinc-400 sm:text-base">
            Personnalisez le nom affiché dans la barre latérale et le logo (SVG, PNG ou WebP recommandés, 2&nbsp;Mo max). Sans logo personnalisé, l’icône par défaut est utilisée.
        </flux:text>
    </header>

    <div class="grid gap-8 lg:grid-cols-2">
        <form wire:submit="save" class="admin-card flex flex-col gap-6">
            <flux:heading size="lg" class="text-zinc-100">Réglages</flux:heading>

            <flux:input
                wire:model="site_name"
                label="Titre dans le menu"
                placeholder="{{ config('app.name') }}"
                description="Laisser vide pour utiliser le nom de l’application (APP_NAME)."
            />

            <div>
                <flux:label>Nouveau logo</flux:label>
                <flux:description class="mb-2">Carré ou paysage ; il sera recadré dans un cadre 32×32 px.</flux:description>
                <input
                    type="file"
                    wire:model="logo"
                    accept=".jpg,.jpeg,.png,.gif,.webp,.svg,image/jpeg,image/png,image/gif,image/webp,image/svg+xml"
                    class="block w-full cursor-pointer rounded-lg border border-zinc-700/80 bg-zinc-900/60 px-3 py-2 text-sm text-zinc-200 file:me-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-emerald-600/20 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-200 hover:file:bg-emerald-600/30"
                />
                @error('logo')
                    <flux:text class="mt-1 text-sm text-red-400">{{ $message }}</flux:text>
                @enderror
                <div wire:loading wire:target="logo" class="mt-2 text-xs text-zinc-500">Téléversement…</div>
            </div>

            @if ($logo)
                @php($ext = strtolower($logo->getClientOriginalExtension()))
                <div class="rounded-xl border border-zinc-700/80 bg-zinc-900/50 p-4">
                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Aperçu</flux:text>
                    @if ($ext === 'svg')
                        <flux:text class="text-sm text-zinc-400">{{ $logo->getClientOriginalName() }}</flux:text>
                    @else
                        <div class="flex size-16 items-center justify-center overflow-hidden rounded-xl border border-zinc-700 bg-zinc-950">
                            <img src="{{ $logo->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain" />
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap gap-3">
                <flux:button variant="primary" type="submit">Enregistrer</flux:button>
            </div>
        </form>

        <div class="admin-card flex flex-col gap-6">
            <flux:heading size="lg" class="text-zinc-100">Aperçu menu</flux:heading>
            <flux:text class="text-sm text-zinc-400">Rendu approximatif de la zone marque dans la barre latérale.</flux:text>

            <div
                class="flex items-center gap-3 rounded-xl border border-zinc-700/80 bg-zinc-950/80 p-4 ring-1 ring-white/5"
            >
                <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-700/80 bg-zinc-900 ring-1 ring-white/5">
                    @if ($branding->logoUrl())
                        <img src="{{ $branding->logoUrl() }}" alt="" class="size-full object-contain p-0.5" />
                    @else
                        <x-app-logo-icon class="size-5 fill-current text-emerald-400" />
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="truncate font-semibold text-zinc-100">{{ $branding->displayName() }}</div>
                    <div class="text-xs text-zinc-500">Barre latérale</div>
                </div>
            </div>

            @if ($branding->logo_path)
                <flux:button variant="danger" type="button" wire:click="openRemoveLogoModal">
                    Retirer le logo
                </flux:button>
            @endif
        </div>
    </div>

    <flux:modal name="confirm-remove-logo" class="max-w-md">
        <flux:heading size="lg">Retirer le logo personnalisé ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-500">
            Le logo sera supprimé du stockage ; l’icône par défaut sera utilisée dans le menu.
        </flux:text>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Annuler</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="button" wire:click="confirmRemoveLogo">Retirer le logo</flux:button>
        </div>
    </flux:modal>
</div>
