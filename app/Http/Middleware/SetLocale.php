<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language: the signed-in user's preference, then the visitor's choice (session),
 * otherwise the organisation default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? ($request->hasSession() ? $request->session()->get('locale') : null);

        if (! array_key_exists((string) $locale, AppSetting::LOCALES)) {
            $locale = AppSetting::current()->locale();
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }
}
