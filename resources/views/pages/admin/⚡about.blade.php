<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('About')] class extends Component {
    /** The update check calls GitHub: run it after the page is shown. */
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function checkAgain(): void
    {
        Cache::forget('about:latest-release');
        unset($this->latest);
    }

    /**
     * Latest published release on GitHub, cached 6 hours (1 hour when GitHub cannot be reached).
     *
     * @return array{tag: ?string, url: ?string, date: ?string}
     */
    #[Computed]
    public function latest(): array
    {
        if (! $this->ready) {
            return ['tag' => null, 'url' => null, 'date' => null];
        }

        $cached = Cache::get('about:latest-release');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(4)->acceptJson()
                ->withHeaders(['User-Agent' => 'o365-kiosk-resources'])
                ->get('https://api.github.com/repos/'.config('app.repository').'/releases/latest');
            $release = [
                'tag' => $response->successful() ? $response->json('tag_name') : null,
                'url' => $response->successful() ? $response->json('html_url') : null,
                'date' => $response->successful() ? $response->json('published_at') : null,
            ];
        } catch (\Throwable) {
            $release = ['tag' => null, 'url' => null, 'date' => null];
        }

        Cache::put('about:latest-release', $release, $release['tag'] ? now()->addHours(6) : now()->addHour());

        return $release;
    }

    /** ok (up to date), update (newer release), dev (not a release build), unknown (no answer). */
    #[Computed]
    public function updateState(): string
    {
        $current = (string) config('app.version'); // app_version() adds a “v” for display
        $latest = $this->latest['tag'];

        return match (true) {
            preg_match('/^\d+\.\d+\.\d+$/', $current) !== 1 => 'dev',
            $latest === null => 'unknown',
            version_compare(ltrim($latest, 'v'), $current, '>') => 'update',
            default => 'ok',
        };
    }

    /** @return list<array{0: string, 1: string, 2: string}> name, version, website */
    #[Computed]
    public function stack(): array
    {
        $version = function (string $package): string {
            try {
                return ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
            } catch (\Throwable) {
                return '—';
            }
        };

        return [
            ['Laravel', $version('laravel/framework'), 'https://laravel.com'],
            ['Livewire', $version('livewire/livewire'), 'https://livewire.laravel.com'],
            ['Flux UI', $version('livewire/flux'), 'https://fluxui.dev'],
            ['Tailwind CSS', '4', 'https://tailwindcss.com'],
            ['PHP', PHP_VERSION, 'https://www.php.net'],
            ['Microsoft Graph', 'v1.0', 'https://learn.microsoft.com/graph/'],
        ];
    }
}; ?>

@php
    $repo = 'https://github.com/'.config('app.repository');
    $state = $this->updateState;
    $latest = $this->latest;
@endphp

<div wire:init="load">
    <x-admin.page-header icon="information-circle" :title="__('About')" :description="__('Version, licence and the project behind this application.')" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-6">
            {{-- Identity --}}
            <section class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-xs shadow-zinc-950/[0.03] sm:p-8 dark:border-white/[0.07] dark:bg-white/[0.025]">
                <div aria-hidden="true" class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-brand/15 blur-3xl"></div>
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-[#2563eb] text-white shadow-lg shadow-blue-600/30">
                        <x-app-logo-icon class="size-8" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white">O365 Kiosk Resources</h2>
                        <p class="mt-1 text-sm text-pretty text-zinc-500 dark:text-zinc-400">{{ __('Microsoft 365 meeting room agendas, displayed in front of every room.') }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 font-mono text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 ring-inset dark:bg-white/[0.06] dark:text-zinc-200 dark:ring-white/10">
                                <flux:icon.tag variant="micro" class="size-3.5 opacity-60" />{{ app_version() }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 ring-inset dark:bg-white/[0.06] dark:text-zinc-200 dark:ring-white/10">
                                <flux:icon.scale variant="micro" class="size-3.5 opacity-60" />{{ __('MIT licence') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 ring-inset dark:bg-white/[0.06] dark:text-zinc-200 dark:ring-white/10">
                                <flux:icon.code-bracket variant="micro" class="size-3.5 opacity-60" />{{ __('Open source') }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Updates --}}
            <x-admin.card icon="arrow-path" :title="__('Updates')">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="min-w-0 flex-1">
                        @if (! $ready)
                            <div class="h-5 w-56 animate-pulse rounded bg-zinc-100 dark:bg-white/5"></div>
                        @elseif ($state === 'update')
                            <p class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white">
                                <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-60 motion-reduce:hidden"></span><span class="relative inline-flex size-2 rounded-full bg-brand"></span></span>
                                {{ __('Version :version is available', ['version' => $latest['tag']]) }}
                            </p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('You are running :version. Pull the new image and restart the containers.', ['version' => app_version()]) }}</p>
                        @elseif ($state === 'ok')
                            <p class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white"><flux:icon.check-circle variant="mini" class="text-available" />{{ __('You are up to date') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __(':version is the latest release.', ['version' => app_version()]) }}</p>
                        @elseif ($state === 'dev')
                            <p class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white"><flux:icon.wrench-screwdriver variant="mini" class="text-zinc-400" />{{ __('Development build') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">
                                {{ __('This copy was not built from a release.') }}
                                @if ($latest['tag']) {{ __('Latest release: :version.', ['version' => $latest['tag']]) }} @endif
                            </p>
                        @else
                            <p class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white"><flux:icon.cloud variant="mini" class="text-zinc-400" />{{ __('Unable to check for updates') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('GitHub could not be reached from the server.') }}</p>
                        @endif
                    </div>
                    @if ($ready)
                        <div class="flex shrink-0 gap-2">
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="checkAgain" wire:loading.attr="disabled" wire:target="checkAgain">{{ __('Check again') }}</flux:button>
                            @if ($latest['url'])
                                <flux:button size="sm" :variant="$state === 'update' ? 'primary' : 'filled'" icon="arrow-top-right-on-square" :href="$latest['url']" target="_blank" rel="noopener noreferrer">{{ __('Release notes') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </div>
                @if ($state === 'update')
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-300 dark:bg-black/40"><span class="text-zinc-500">$</span> docker compose pull
<span class="text-zinc-500">$</span> docker compose up -d</pre>
                @endif
            </x-admin.card>

            {{-- Built with --}}
            <x-admin.card icon="cube" :title="__('Built with')" :padded="false">
                <ul class="grid divide-zinc-100 sm:grid-cols-2 dark:divide-white/[0.06]">
                    @foreach ($this->stack as [$name, $version, $url])
                        <li class="border-b border-zinc-100 sm:odd:border-e dark:border-white/[0.06]">
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-3 px-5 py-3 text-sm transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $name }}</span>
                                <span class="font-mono text-xs text-zinc-500">{{ $version }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        </div>

        {{-- Links --}}
        <aside class="space-y-6">
            <x-admin.card icon="link" :title="__('Links')" :padded="false">
                <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach ([
                        ['globe-alt', __('Website'), config('app.website')],
                        ['code-bracket', __('Source code'), $repo],
                        ['tag', __('Releases'), $repo.'/releases'],
                        ['bug-ant', __('Report a bug or suggest an idea'), $repo.'/issues/new'],
                        ['book-open', __('Documentation'), $repo.'#readme'],
                    ] as [$icon, $label, $url])
                        <li>
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="group flex items-center gap-3 px-5 py-3 text-sm text-zinc-700 transition hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/[0.03] dark:hover:text-white">
                                <flux:icon :name="$icon" variant="mini" class="size-4 text-zinc-400 group-hover:text-brand" />
                                <span class="flex-1">{{ $label }}</span>
                                <flux:icon.arrow-up-right variant="micro" class="size-3.5 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-500 dark:text-zinc-600" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            <div class="rounded-2xl border border-dashed border-zinc-300 p-5 text-sm dark:border-white/10">
                <p class="font-medium text-zinc-900 dark:text-white">{{ __('Licence') }}</p>
                <p class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Released under the MIT licence: free to use, modify and redistribute, with no warranty.') }}</p>
                <p class="mt-3 text-xs text-zinc-400">© 2026 pokertour</p>
                <p class="mt-3 text-xs text-zinc-400">{{ __('Diagnostics and settings stay on your server; this page only asks GitHub for the latest release number.') }}</p>
            </div>
        </aside>
    </div>
</div>
