<?php

// The newest mobile app, set in .env: installed apps read it (GET /api/app/release) when they open
// and offer the update. "Build" is the number after the + in the app's pubspec version
// (1.0.1+4 is build 4). Change these when you publish a new APK; after editing .env, run
// `php artisan config:clear` (or restart the server) if the config is cached.
return [

    // The version text shown to people, e.g. 1.0.1.
    'latest_version' => env('MOBILE_LATEST_VERSION'),

    // The newest build. Installed apps with a lower build are offered the update.
    'latest_build' => (int) env('MOBILE_LATEST_BUILD', 0),

    // The oldest build still accepted. Apps below it are blocked until they update. 0 blocks nobody.
    'min_build' => (int) env('MOBILE_MIN_BUILD', 0),

    // Where the APK can be downloaded.
    'apk_url' => env('MOBILE_APK_URL'),

];
