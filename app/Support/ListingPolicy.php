<?php

namespace App\Support;

// The system administrator's marketplace listing policy (Settings →
// Marketplace Policy): for each "listing polish" item, whether a branch must
// have it before clients can see it in the app (required) or it only counts
// toward the owner's polish score (optional).
//
// Enforced by MarketplaceReadiness, which every public list and the booking
// endpoints already go through. Stored on system_settings.listing_policy.
final class ListingPolicy
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';

    public const MIN_HIGHLIGHTS = 3;

    /** key => what the owner sees */
    public const ITEMS = [
        'cover_photo' => 'Cover photo uploaded',
        'description' => 'Business description filled',
        'highlights' => 'At least 3 highlights chosen',
        'promo' => 'Promo banner set',
    ];

    // What applies until an administrator changes it (and while the column
    // doesn't exist yet).
    public const DEFAULTS = [
        'cover_photo' => self::REQUIRED,
        'description' => self::REQUIRED,
        'highlights' => self::REQUIRED,
        'promo' => self::OPTIONAL,
    ];

    /**
     * A complete policy from whatever was stored or sent: unknown keys are
     * dropped, a missing key takes its default, and anything that isn't
     * exactly "required" is optional.
     *
     * @return array<string, string>
     */
    public static function normalize(?array $policy): array
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $policy[$key] ?? $default;
            $clean[$key] = $value === self::REQUIRED ? self::REQUIRED : self::OPTIONAL;
        }

        return $clean;
    }

    /** The policy in force (cached with the rest of the system settings). */
    public static function current(): array
    {
        return AppCache::systemSettings()->listingPolicy();
    }

    /** @return list<string> the keys a branch must have to be listed */
    public static function requiredKeys(?array $policy = null): array
    {
        return array_keys(array_filter($policy ?? self::current(), fn ($v) => $v === self::REQUIRED));
    }
}
