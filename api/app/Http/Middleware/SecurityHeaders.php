<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Safe defaults on every API response. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        // API answers are data, never pages to run.
        if (! $h->has('Content-Security-Policy') && str_contains((string) $h->get('Content-Type'), 'json')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }
        // Signed-in data must not be kept by shared caches.
        if ($request->bearerToken()) {
            $h->set('Cache-Control', 'no-store, private');
        }
        $h->remove('X-Powered-By');
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        return $response;
    }
}
