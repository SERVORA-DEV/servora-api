<?php

namespace App\Support;

// The newest version of the Servora mobile app, and the oldest the server still accepts, read from
// .env through config/mobile.php. The app asks for this (GET /api/app/release) when it opens and
// tells the person to update.
//
// "build" is the number after the + in the app's pubspec version (1.0.1+4 is build 4): the app
// compares builds, not the version text.
class MobileRelease
{
    /** @return array{latest_version: string|null, latest_build: int, min_build: int, apk_url: string|null} */
    public function current(): array
    {
        $latest = max(0, (int) config('mobile.latest_build', 0));

        return [
            'latest_version' => config('mobile.latest_version') ?: null,
            'latest_build' => $latest,
            // Never past the newest build: nobody could meet it. 0 blocks nobody.
            'min_build' => min($latest, max(0, (int) config('mobile.min_build', 0))),
            'apk_url' => config('mobile.apk_url') ?: null,
        ];
    }
}
