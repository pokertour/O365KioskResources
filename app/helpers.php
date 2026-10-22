<?php

use App\Models\AppSetting;
use Carbon\CarbonImmutable;

if (! function_exists('fmt_time')) {
    /**
     * Clock time in the configured format (14:30 or 2:30 PM).
     */
    function fmt_time(DateTimeInterface $date): string
    {
        return $date->format(AppSetting::current()->uses12h() ? 'g:i A' : 'H:i');
    }
}

if (! function_exists('fmt_day')) {
    /**
     * Long day label in the current locale (« jeudi 22 octobre » / “Thursday, October 22”).
     */
    function fmt_day(DateTimeInterface $date): string
    {
        $locale = app()->getLocale();

        return CarbonImmutable::instance($date)
            ->locale($locale)
            ->isoFormat($locale === 'fr' ? 'dddd D MMMM' : 'dddd, MMMM D');
    }
}

if (! function_exists('fmt_duration')) {
    /**
     * Compact duration: 45 min, 1 h, 1 h 30.
     */
    function fmt_duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $rest = $minutes % 60;

        return intdiv($minutes, 60).' h'.($rest ? ' '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }
}
