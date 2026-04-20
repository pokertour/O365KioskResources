<?php

use App\Models\BrandingSetting;
use App\Models\MicrosoftSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Configuration')] class extends Component {
    use WithFileUploads;

    public string $site_name = '';

    public $logo = null;

    public string $azure_tenant_id = '';

    public string $azure_client_id = '';

    public string $azure_client_secret = '';

    public ?string $saved_azure_tenant_id = null;

    public ?string $saved_azure_client_id = null;

    public function mount(): void
    {
        $b = BrandingSetting::current();
        $this->site_name = (string) ($b->site_name ?? '');

        if (Schema::hasTable('microsoft_settings')) {
            $m = MicrosoftSetting::current();
            $this->azure_tenant_id = (string) ($m->tenant_id ?? '');
            $this->azure_client_id = (string) ($m->client_id ?? '');
            $this->saved_azure_tenant_id = $this->azure_tenant_id !== '' ? $this->azure_tenant_id : null;
            $this->saved_azure_client_id = $this->azure_client_id !== '' ? $this->azure_client_id : null;
        }
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

    public function saveMicrosoftConfig(): void
    {
        if (! Schema::hasTable('microsoft_settings')) {
            Flux::toast(variant: 'danger', text: 'Migration manquante : exécutez php artisan migrate.');

            return;
        }

        $this->validate([
            'azure_tenant_id' => ['required', 'string', 'max:255'],
            'azure_client_id' => ['required', 'string', 'max:255'],
            'azure_client_secret' => ['nullable', 'string', 'max:2048'],
        ], [], [
            'azure_tenant_id' => 'Tenant ID',
            'azure_client_id' => 'Client ID',
            'azure_client_secret' => 'Client secret',
        ]);

        $m = MicrosoftSetting::current();
        $m->tenant_id = trim($this->azure_tenant_id);
        $m->client_id = trim($this->azure_client_id);
        if (trim($this->azure_client_secret) !== '') {
            $m->client_secret = trim($this->azure_client_secret);
        }
        $m->save();

        // Force token refresh after credentials update.
        Cache::forget('microsoft_graph.access_token');

        $this->saved_azure_tenant_id = $m->tenant_id !== '' ? $m->tenant_id : null;
        $this->saved_azure_client_id = $m->client_id !== '' ? $m->client_id : null;
        $this->azure_client_secret = '';
        Flux::toast(variant: 'success', text: 'Configuration Microsoft 365 enregistrée.');
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
        <flux:heading size="xl" class="text-balance text-zinc-900 dark:text-zinc-50">Configuration</flux:heading>
        <flux:text class="mt-2 max-w-2xl text-pretty text-sm leading-relaxed text-zinc-600 dark:text-zinc-400 sm:text-base">
            Regroupez ici l’identité visuelle et la configuration Microsoft 365.
        </flux:text>
    </header>

    <div x-data="{ tab: 'branding' }" class="space-y-6">
        <div class="inline-flex rounded-xl border border-zinc-300 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="tab === 'branding' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
                x-on:click="tab = 'branding'"
            >
                Identité visuelle
            </button>
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="tab === 'microsoft' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
                x-on:click="tab = 'microsoft'"
            >
                Microsoft 365
            </button>
        </div>

        <div x-show="tab === 'branding'" x-cloak class="grid gap-8 lg:grid-cols-2">
            <form wire:submit="save" class="admin-card flex flex-col gap-6">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">Réglages</flux:heading>

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
                        class="block w-full cursor-pointer rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-800 file:me-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-emerald-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-emerald-800 hover:file:bg-emerald-200 dark:border-zinc-700/80 dark:bg-zinc-900/60 dark:text-zinc-200 dark:file:bg-emerald-600/20 dark:file:text-emerald-200 dark:hover:file:bg-emerald-600/30"
                    />
                    @error('logo')
                        <flux:text class="mt-1 text-sm text-red-400">{{ $message }}</flux:text>
                    @enderror
                    <div wire:loading wire:target="logo" class="mt-2 text-xs text-zinc-600 dark:text-zinc-500">Téléversement…</div>
                </div>

                @if ($logo)
                    @php($ext = strtolower($logo->getClientOriginalExtension()))
                    <div class="rounded-xl border border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-700/80 dark:bg-zinc-900/50">
                        <flux:text class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-600 dark:text-zinc-500">Aperçu</flux:text>
                        @if ($ext === 'svg')
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ $logo->getClientOriginalName() }}</flux:text>
                        @else
                            <div class="flex size-16 items-center justify-center overflow-hidden rounded-xl border border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-950">
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
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">Aperçu menu</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Rendu approximatif de la zone marque dans la barre latérale.</flux:text>

                <div
                    class="flex items-center gap-3 rounded-xl border border-zinc-300 bg-white p-4 ring-1 ring-zinc-100 dark:border-zinc-700/80 dark:bg-zinc-950/80 dark:ring-white/5"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-300 bg-white ring-1 ring-zinc-100 dark:border-zinc-700/80 dark:bg-zinc-900 dark:ring-white/5">
                        @if ($branding->logoUrl())
                            <img src="{{ $branding->logoUrl() }}" alt="" class="size-full object-contain p-0.5" />
                        @else
                            <x-app-logo-icon class="size-5 fill-current text-emerald-400" />
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="truncate font-semibold text-zinc-900 dark:text-zinc-100">{{ $branding->displayName() }}</div>
                        <div class="text-xs text-zinc-600 dark:text-zinc-500">Barre latérale</div>
                    </div>
                </div>

                @if ($branding->logo_path)
                    <flux:button variant="danger" type="button" wire:click="openRemoveLogoModal">
                        Retirer le logo
                    </flux:button>
                @endif
            </div>
        </div>

        <form wire:submit="saveMicrosoftConfig" x-show="tab === 'microsoft'" x-cloak class="admin-card mt-2 flex flex-col gap-6">
            <div>
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">Microsoft 365 (Azure)</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Configurez ici les identifiants Microsoft Graph. Le secret n’est pas ré-affiché pour sécurité.
                </flux:text>
            </div>
            @if ($saved_azure_tenant_id || $saved_azure_client_id)
                <div class="rounded-xl border border-zinc-300 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="font-medium text-zinc-900 dark:text-zinc-100">Valeurs actuellement enregistrées</div>
                    @if ($saved_azure_tenant_id)
                        <div class="mt-2 text-zinc-700 dark:text-zinc-300">
                            Tenant ID : <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-800">{{ $saved_azure_tenant_id }}</code>
                        </div>
                    @endif
                    @if ($saved_azure_client_id)
                        <div class="mt-2 text-zinc-700 dark:text-zinc-300">
                            Client ID : <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-800">{{ $saved_azure_client_id }}</code>
                        </div>
                    @endif
                </div>
            @endif
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="azure_tenant_id" label="Tenant ID" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required />
                <flux:input wire:model="azure_client_id" label="Client ID" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required />
            </div>
            <flux:input
                wire:model="azure_client_secret"
                type="password"
                label="Client secret (laisser vide pour conserver l’actuel)"
                autocomplete="new-password"
                viewable
            />
            <div class="flex flex-wrap gap-3">
                <flux:button variant="primary" type="submit">Enregistrer la configuration Microsoft 365</flux:button>
            </div>
        </form>
    </div>

    <flux:modal name="confirm-remove-logo" class="max-w-md">
        <flux:heading size="lg">Retirer le logo personnalisé ?</flux:heading>
        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-500">
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
