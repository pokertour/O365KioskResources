<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="description" content="Affichage des disponibilités des salles de réunion Microsoft 365 et réservations depuis un kiosque." />

        <title>{{ config('app.name') }} — Planning salles</title>

        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link rel="icon" href="/favicon.svg" type="image/svg+xml" />
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" />

        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <div class="pointer-events-none fixed inset-0 -z-10 bg-[radial-gradient(ellipse_80%_50%_at_50%_-20%,rgba(16,185,129,0.12),transparent)]"></div>

        <header class="border-b border-zinc-800/80 bg-zinc-950/80 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <span class="text-sm font-semibold tracking-tight text-zinc-100">{{ config('app.name') }}</span>
                <nav class="flex items-center gap-2 sm:gap-3">
                    @auth
                        <a
                            href="{{ auth()->user()->is_admin ? route('admin.rooms.index') : route('dashboard') }}"
                            wire:navigate
                            class="rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm font-medium text-zinc-100 transition hover:border-emerald-500/40 hover:bg-zinc-800"
                        >
                            Espace connecté
                        </a>
                    @else
                        @if (Route::has('login'))
                            <a
                                href="{{ route('login') }}"
                                wire:navigate
                                class="rounded-lg border border-emerald-600/50 bg-emerald-600/15 px-3 py-2 text-sm font-medium text-emerald-100 transition hover:bg-emerald-600/25"
                            >
                                Connexion admin
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-24">
            <div class="max-w-2xl">
                <p class="text-sm font-medium text-emerald-400/90">Microsoft 365</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl sm:leading-tight">
                    Planning des salles sur tablette, à l’entrée des réunions.
                </h1>
                <p class="mt-5 text-base leading-relaxed text-zinc-400">
                    Chaque salle dispose d’une page kiosque sécurisée : agenda du jour, disponibilité en temps réel et réservation rapide. L’administration se fait depuis un espace dédié, sans inscription publique.
                </p>
            </div>

            <div class="mt-14 grid gap-6 sm:grid-cols-2">
                <article class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-sm shadow-black/20">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5a2.25 2.25 0 002.25-2.25m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5a2.25 2.25 0 012.25 2.25v7.5" />
                        </svg>
                    </div>
                    <h2 class="mt-4 text-lg font-semibold text-zinc-100">Kiosque salle</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                        L’URL est fournie par votre administrateur : identifiant de la salle et token secret dans le lien. Aucun compte à saisir sur la tablette.
                    </p>
                </article>

                <article class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-sm shadow-black/20">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-500/15 text-zinc-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                    </div>
                    <h2 class="mt-4 text-lg font-semibold text-zinc-100">Administration</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                        Gestion des salles, des réservations Microsoft 365 et des comptes administrateurs. Accès réservé aux personnes autorisées.
                    </p>
                    @if (Route::has('login') && ! auth()->check())
                        <a
                            href="{{ route('login') }}"
                            wire:navigate
                            class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-emerald-400 transition hover:text-emerald-300"
                        >
                            Se connecter
                            <span aria-hidden="true">→</span>
                        </a>
                    @endif
                </article>
            </div>

            <p class="mt-16 max-w-xl text-xs leading-relaxed text-zinc-600">
                Les créneaux affichés et créés proviennent du calendrier de la ressource salle dans Microsoft 365. Configurez l’application Entra ID et les variables d’environnement Azure sur le serveur pour activer la synchronisation.
            </p>
        </main>

        <footer class="border-t border-zinc-800/80 py-6 text-center text-xs text-zinc-600">
            {{ config('app.name') }} — {{ now()->year }}
        </footer>

        @fluxScripts
    </body>
</html>
