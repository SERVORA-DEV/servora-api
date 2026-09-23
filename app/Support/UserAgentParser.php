<?php

namespace App\Support;

use Illuminate\Support\Str;

// Turns a raw User-Agent string into a short "Browser on OS" label for
// display (Login Sessions, Login History) — no composer dependency (e.g.
// jenssegers/agent), just enough regex matching to cover the browsers/OSes
// that actually show up in practice. Order matters: some UAs claim to be
// multiple browsers at once (Edge/Opera both include "Chrome", most mobile
// browsers include "Safari"), and iOS UAs include "like Mac OS X" and
// Android UAs include "Linux", so the more specific match must run first.
class UserAgentParser
{
    // The Flutter mobile app's HTTP client (dart:io) — not a browser, so it
    // gets its own label instead of falling through to the raw string.
    private const APP_MARKER = 'Dart/';
    private const APP_LABEL = 'Servora Mobile App';

    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Opera' => 'Opera',
        'CriOS' => 'Chrome',
        'FxiOS' => 'Firefox',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
    ];

    private const OS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        if (str_contains($userAgent, self::APP_MARKER)) {
            return self::APP_LABEL;
        }

        $browser = self::match($userAgent, self::BROWSERS);
        $os = self::match($userAgent, self::OS);

        if ($browser && $os) {
            return "{$browser} on {$os}";
        }

        if ($browser) {
            return $browser;
        }

        // Nothing recognized — fall back to a truncated raw string rather
        // than a bare "Unknown device", so an unusual client (a bot, an API
        // tool, a niche browser) still shows something identifiable.
        return Str::limit($userAgent, 60, '');
    }

    /**
     * @return 'mobile'|'tablet'|'desktop' — drives the device icon.
     */
    public static function deviceType(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'desktop';
        }

        if (str_contains($userAgent, 'iPad')
            || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile'))) {
            return 'tablet';
        }

        if (str_contains($userAgent, 'iPhone')
            || str_contains($userAgent, 'Android')
            || str_contains($userAgent, self::APP_MARKER)) {
            return 'mobile';
        }

        return 'desktop';
    }

    private static function match(string $userAgent, array $map): ?string
    {
        foreach ($map as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
