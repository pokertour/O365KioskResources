<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Kiosk and phone URLs carry an access token or signature: never leak them via Referer, caches or search engines.
        if ($request->routeIs('kiosk.*', 'room.mobile')) {
            $headers->set('Referrer-Policy', 'no-referrer');
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
