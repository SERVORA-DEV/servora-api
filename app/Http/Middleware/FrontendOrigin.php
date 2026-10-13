<?php

namespace App\Http\Middleware;

use App\Support\AllowedOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Links the API gives the browser follow the address the site was opened at.
//
// FRONTEND_URL is one address, but the site is reached at several: localhost,
// a LAN IP, a forwarded port, the hosted domain. For the length of a request
// that comes from a trusted front end (App\Support\AllowedOrigin — the CORS
// allow-list), config('app.frontend_url') is that front end's own origin, so
// the checkout return page, the subscription redirect and the like go back to
// where the person actually is. Everything without such a header — the phone
// app, a webhook, a link clicked in an email, a queued job — keeps
// FRONTEND_URL.
//
// Each request is its own PHP process here (php artisan serve, php-fpm), so
// the value set for one never reaches another.
class FrontendOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = AllowedOrigin::from(
            $request->headers->get('Origin'),
            $request->headers->get('Referer'),
            (array) config('cors.allowed_origins', []),
            (array) config('cors.allowed_origins_patterns', []),
        );

        if ($origin !== null) {
            config(['app.frontend_url' => $origin]);
        }

        return $next($request);
    }
}
