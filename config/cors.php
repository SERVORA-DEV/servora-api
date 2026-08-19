<?php

return [

    // Only the API surface (and Sanctum's CSRF cookie route, unused today
    // since auth is token-based, but harmless to include) needs CORS at
    // all — never web.php.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Explicit allow-list rather than '*' — CORS is a browser-only
    // mechanism anyway (Flutter's HTTP client ignores it entirely), so this
    // only ever gates the Nuxt dev server. FRONTEND_URL comes from .env;
    // the two localhost fallbacks and the LAN IP cover `npm run dev`
    // whether it's opened from this laptop or from another device's
    // browser on the same Wi-Fi. Update FRONTEND_URL (or add another entry
    // here) if Nuxt runs on a different host/port.
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])),

    'allowed_origins_patterns' => [
        // Any device on the LAN opening Nuxt's dev server by IP, on any port.
        '#^http://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^http://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        // Radmin VPN — assigns addresses in 26.0.0.0/8 to connected peers,
        // so a remote machine on the same Radmin network can reach this
        // laptop's Laravel API the same way a LAN device does.
        '#^http://26\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Auth is Sanctum bearer tokens (Authorization header), not cookies —
    // no stateful/SPA session to protect, so credentialed CORS isn't needed.
    'supports_credentials' => false,

];
