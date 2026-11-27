<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply baseline browser hardening headers and non-cacheable auth responses.
 */
class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()', false);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'",
            false
        );

        if ($request->routeIs('login', 'login.store', 'logout', 'dashboard')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
