@props([
    // id of the <form> the side "Save" button submits; no button when null.
    'form' => null,
])

{{-- Settings page with a sticky side column on wide screens: an "On this page" outline built
     from the cards' titles (highlighting the one in view) and an always-reachable Save button. --}}
<div
    {{ $attributes->class('xl:grid xl:grid-cols-[minmax(0,1fr)_14rem] xl:gap-10') }}
    x-data="{
        sections: [],
        current: null,
        init() {
            this.sections = [...this.$refs.content.querySelectorAll('section[id] > div h2')]
                .map(h => ({ id: h.closest('section').id, title: h.textContent.trim() }))
            const observer = new IntersectionObserver(entries => {
                const visible = entries.filter(e => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
                if (visible.length) this.current = visible[0].target.id
            }, { rootMargin: '-15% 0px -60% 0px' })
            this.sections.forEach(s => observer.observe(document.getElementById(s.id)))
            this.current = this.sections[0]?.id
        },
        atBottom() {
            if (this.sections.length && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
                this.current = this.sections[this.sections.length - 1].id
            }
        },
    }"
    x-on:scroll.window.throttle.100ms="atBottom()"
>
    <div x-ref="content" class="min-w-0 max-w-3xl">
        {{ $slot }}
    </div>

    <aside class="hidden xl:block">
        <div class="sticky top-8 space-y-6">
            <nav x-show="sections.length > 1" x-cloak aria-label="{{ __('On this page') }}">
                <p class="mb-2 text-[11px] font-semibold tracking-wider text-zinc-400 uppercase dark:text-zinc-500">{{ __('On this page') }}</p>
                <ul class="space-y-0.5 border-s border-zinc-200 dark:border-white/10">
                    <template x-for="section in sections" :key="section.id">
                        <li>
                            <a
                                x-bind:href="`#${section.id}`"
                                x-on:click.prevent="document.getElementById(section.id).scrollIntoView({ behavior: 'smooth', block: 'start' }); current = section.id"
                                x-text="section.title"
                                x-bind:class="current === section.id
                                    ? 'border-brand text-zinc-900 font-medium dark:text-white'
                                    : 'border-transparent text-zinc-500 hover:text-zinc-900 hover:border-zinc-300 dark:hover:text-white dark:hover:border-white/20'"
                                class="-ms-px block border-s-2 py-1 ps-3 text-sm transition"
                            ></a>
                        </li>
                    </template>
                </ul>
            </nav>

            @if ($form)
                <flux:button type="submit" :form="$form" variant="primary" icon="check" class="w-full">{{ __('Save') }}</flux:button>
            @endif

            {{ $aside ?? '' }}
        </div>
    </aside>
</div>
