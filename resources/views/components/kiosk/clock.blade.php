@props([
    'options',
    'now',
    'night' => false,
])

{{-- Live clock, ticking in the browser in the configured time zone and format. --}}
<div
    {{ $attributes->class([$night ? 'text-center' : 'text-right']) }}
    x-data="{
        time: '',
        date: '',
        init() {
            const o = @js($options);
            const t = new Intl.DateTimeFormat(o.locale, { hour: 'numeric', minute: '2-digit', hour12: o.hour12, timeZone: o.timeZone });
            const d = new Intl.DateTimeFormat(o.locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: o.timeZone });
            const tick = () => { const n = new Date(); this.time = t.format(n); this.date = d.format(n) };
            tick();
            setInterval(tick, 1000);
        },
    }"
>
    <div @class([
        'font-semibold tabular-nums tracking-tight',
        'text-[min(22vmin,10rem)] leading-none font-light text-zinc-400' => $night,
        'text-4xl' => ! $night,
    ]) x-text="time">{{ fmt_time($now) }}</div>
    <div @class([
        'capitalize',
        'mt-2 text-xl text-zinc-600' => $night,
        'text-base text-zinc-500 dark:text-zinc-400' => ! $night,
    ]) x-text="date">{{ fmt_day($now) }}</div>
</div>
