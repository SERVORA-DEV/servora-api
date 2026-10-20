<?php

namespace App\Support;

use App\Models\SpaBranch;

// A spa's Facebook page, Instagram and website, as the owner typed them
// ("facebook.com/lotushaven", "@lotushaven", "lotushaven.ph") turned into
// links a person can tap — on the web's branch screen and the mobile app's.
//
// A branch has its own links (Branch Settings → Branch Details) and the
// business has company-wide ones (Business Identity). A branch shows its own;
// for any it hasn't set it shows the company's, so a spa with one Facebook
// page doesn't have to type it again at every branch.
//
// Only web links come out: anything else a person might type (javascript:,
// mailto:, a phone number) is not a link, and is refused when saved (see
// App\Rules\SocialLink). Pure — tested in tests/Unit/SocialLinksTest.
final class SocialLinks
{
    public const FACEBOOK = 'facebook';
    public const INSTAGRAM = 'instagram';
    public const WEBSITE = 'website';
    public const KINDS = [self::FACEBOOK, self::INSTAGRAM, self::WEBSITE];

    private const FACEBOOK_HOST = '/^(?:(?:www|m|web|business)\.)?(?:facebook\.com|fb\.com|fb\.me)$/i';
    private const INSTAGRAM_HOST = '/^(?:www\.)?instagram\.com$/i';

    /** The link to open, or null when there is none (or what was typed isn't one). */
    public static function url(string $kind, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }

        return match ($kind) {
            self::FACEBOOK => self::facebook($value),
            self::INSTAGRAM => self::instagram($value),
            self::WEBSITE => self::website($value),
            default => null,
        };
    }

    /** Whether it may be saved: empty is fine (no link), anything else must make a link. */
    public static function valid(string $kind, ?string $value): bool
    {
        return trim((string) $value) === '' || self::url($kind, $value) !== null;
    }

    /** What a link is called on screen: "Facebook", "@lotushaven", "lotushaven.ph". */
    public static function label(string $kind, string $url): string
    {
        $parts = parse_url($url) ?: [];

        return match ($kind) {
            self::INSTAGRAM => ($handle = self::firstSegment($parts['path'] ?? '')) !== '' ? '@' . ltrim($handle, '@') : 'Instagram',
            self::WEBSITE => preg_replace('/^www\./i', '', $parts['host'] ?? 'Website'),
            default => 'Facebook',
        };
    }

    /**
     * The links a branch shows: its own, else the company's, per kind.
     *
     * @return array<string, array{url: string, label: string, source: string}> only the kinds that have a link
     */
    public static function forBranch(SpaBranch $branch): array
    {
        $business = $branch->business;
        $own = [
            self::FACEBOOK => $branch->facebook_url,
            self::INSTAGRAM => $branch->instagram_handle,
            self::WEBSITE => $branch->website_url,
        ];
        $company = [
            self::FACEBOOK => $business?->facebook_url,
            self::INSTAGRAM => $business?->instagram_handle,
            self::WEBSITE => $business?->website_url,
        ];

        $links = [];
        foreach (self::KINDS as $kind) {
            foreach (['branch' => $own, 'company' => $company] as $source => $values) {
                if ($url = self::url($kind, $values[$kind])) {
                    $links[$kind] = ['url' => $url, 'label' => self::label($kind, $url), 'source' => $source];
                    break;
                }
            }
        }

        return $links;
    }

    private static function facebook(string $value): ?string
    {
        $bare = preg_replace('#^https?://#i', '', $value);
        $host = strtolower(explode('/', $bare, 2)[0]);
        if (preg_match(self::FACEBOOK_HOST, $host)) {
            return self::https($value);
        }

        // Just the page's name: "lotushaven".
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) && ! str_contains($value, '://')
            ? 'https://facebook.com/' . $value
            : null;
    }

    private static function instagram(string $value): ?string
    {
        $bare = preg_replace('#^https?://#i', '', $value);
        $host = strtolower(explode('/', $bare, 2)[0]);
        if (preg_match(self::INSTAGRAM_HOST, $host)) {
            return self::https($value);
        }

        $handle = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) ? 'https://instagram.com/' . $handle : null;
    }

    private static function website(string $value): ?string
    {
        return self::https($value, mustHaveDot: true);
    }

    // The address with https:// in front when it has no scheme; null when it
    // isn't a web address (another scheme, no host, a host that can't exist).
    private static function https(string $value, bool $mustHaveDot = true): ?string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $value, $m) && ! in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return null;
        }
        $url = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (! preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/', $host)) {
            return $mustHaveDot ? null : $url;
        }

        return $url;
    }

    private static function firstSegment(string $path): string
    {
        return explode('/', trim($path, '/'))[0] ?? '';
    }
}
