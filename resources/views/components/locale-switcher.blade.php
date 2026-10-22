{{-- Segmented FR / EN control. Posts to the locale route, which remembers the choice. --}}
<form method="POST" action="{{ route('locale.update') }}" {{ $attributes->class('inline-flex rounded-lg bg-zinc-100 p-0.5 text-xs font-semibold dark:bg-white/5') }}>
    @csrf
    @foreach (\App\Models\AppSetting::LOCALES as $code => $label)
        <button
            type="submit"
            name="locale"
            value="{{ $code }}"
            title="{{ $label }}"
            lang="{{ $code }}"
            @class([
                'rounded-md px-2.5 py-1 uppercase tracking-wide transition',
                'bg-white text-zinc-900 shadow-xs dark:bg-white/10 dark:text-white' => app()->getLocale() === $code,
                'text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => app()->getLocale() !== $code,
            ])
            @if (app()->getLocale() === $code) aria-current="true" @endif
        >{{ $code }}</button>
    @endforeach
</form>
