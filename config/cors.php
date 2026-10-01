<?php

$isProduction = env('APP_ENV', 'production') === 'production';

// Production origins: FRONTEND_URL plus any extras in CORS_ALLOWED_ORIGINS
// (comma-separated, e.g. a staging site or a custom domain). Trailing
// slashes are stripped — browsers send Origin without one, so a stray "/"
// in .env would silently never match.
$configured = array_map(
    fn ($o) => rtrim(trim($o), '/'),
    array_merge(
        [env('FRONTEND_URL', '')],
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ),
);

return [

    // Only the API surface (and Sanctum's CSRF cookie route, unused today
    // since auth is token-based) needs CORS — never web.php. Server-to-server
    // callers (Xendit webhooks) and the Flutter client ignore CORS entirely.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        $configured,
        // Dev conveniences only — never allow localhost origins in production.
        $isProduction ? [] : ['http://localhost:3000', 'http://127.0.0.1:3000'],
    )))),

    // Dev only: any device on the LAN / Radmin VPN opening Nuxt by IP (incl.
    // `pnpm dev:https`), and Flutter web dev (`flutter run -d chrome`), which
    // binds a random localhost port per run. Empty in production so only the
    // explicit allow-list above can call the API from a browser.
    'allowed_origins_patterns' => $isProduction ? [] : [
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://26\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://localhost:\d+$#',
        '#^https?://127\.0\.0\.1:\d+$#',
    ],

    // Only what the clients actually send (the web app sends Accept and
    // Authorization), rather than '*'. Add a header here if a client starts
    // sending a new one, or its browser preflight will fail.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    // Lets the browser read the filename on report/file downloads.
    'exposed_headers' => ['Content-Disposition'],

    // Cache preflight responses for 10 minutes instead of one OPTIONS request
    // before every call.
    'max_age' => 600,

    // Auth is Sanctum bearer tokens (Authorization header), not cookies —
    // no stateful/SPA session to protect, so credentialed CORS isn't needed.
    'supports_credentials' => false,

];
