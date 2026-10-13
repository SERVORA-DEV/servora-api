<?php

namespace App\Support;

// Which front end is talking to the API right now — when it is one the API
// trusts.
//
// The site can be reached at several addresses: localhost, a LAN IP, a
// forwarded port (a dev tunnel), the hosted domain. Links the API hands back
// to the browser (the page to return to after paying, the page an email
// verification lands on) should go to the address the person is actually
// using, not to one fixed FRONTEND_URL. The browser says where it is in the
// Origin header (or the Referer, for a plain GET); it is only believed when
// it is on the CORS allow-list (config/cors.php), so nobody can make the API
// redirect to a site of their own.
//
// Pure — tested in tests/Unit/AllowedOriginTest.
// App\Http\Middleware\FrontendOrigin applies it.
class AllowedOrigin
{
    /**
     * @param  string[]  $allowed  exact origins (config cors.allowed_origins)
     * @param  string[]  $patterns  regexes (config cors.allowed_origins_patterns)
     * @return ?string  the trusted origin ("https://host[:port]"), or null
     */
    public static function from(?string $origin, ?string $referer, array $allowed, array $patterns): ?string
    {
        foreach ([self::normalize($origin), self::normalize($referer)] as $candidate) {
            if ($candidate !== null && self::isAllowed($candidate, $allowed, $patterns)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function isAllowed(string $origin, array $allowed, array $patterns): bool
    {
        if (in_array($origin, $allowed, true)) {
            return true;
        }
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /** "https://Example.com:3000/some/page?x=1" → "https://example.com:3000"; null when it isn't an http(s) address. */
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || $url === 'null') {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * An entry of CORS_ALLOWED_ORIGINS with a * in it ("https://*.example.com")
     * as a pattern: the * stands for one host label.
     */
    public static function wildcardPattern(string $origin): string
    {
        return '#^' . str_replace('\*', '[a-z0-9-]+', preg_quote(rtrim(trim($origin), '/'), '#')) . '$#i';
    }
}
