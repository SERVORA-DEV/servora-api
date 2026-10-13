<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

// Which of an account's sign-ins are sessions someone is actually using.
//
// Every sign-in leaves a token behind, and a browser that is simply closed
// never says goodbye: its token stays valid until it expires, long after
// anyone could use it. Listing all of those as "devices signed in" shows one
// laptop nine times. So a session is ACTIVE when it has been used in the
// last ACTIVE_MINUTES (an open tab keeps asking the API for things); the rest
// are IDLE — still valid, so they are counted and can be ended, but not
// listed one by one. A token past its lifetime is neither: it no longer works.
//
// Pure — tested in tests/Unit/SessionActivityTest. Used by
// App\Service\System\SecurityService::sessions.
class SessionActivity
{
    public const ACTIVE_MINUTES = 30;

    /** Used (or, never used yet, signed in) within the last ACTIVE_MINUTES. */
    public static function active(DateTimeInterface|string|null $lastUsedAt, DateTimeInterface|string|null $createdAt, DateTimeInterface $now): bool
    {
        $seen = $lastUsedAt ?? $createdAt;
        if ($seen === null) {
            return false;
        }

        return Carbon::parse($seen)->gt(Carbon::instance($now)->subMinutes(self::ACTIVE_MINUTES));
    }

    /**
     * Past its own end date, or older than the lifetime every token has
     * (config sanctum.expiration, in minutes; null: tokens don't expire).
     */
    public static function expired(DateTimeInterface|string|null $createdAt, DateTimeInterface|string|null $expiresAt, DateTimeInterface $now, ?int $lifetimeMinutes): bool
    {
        $now = Carbon::instance($now);
        if ($expiresAt !== null && Carbon::parse($expiresAt)->lte($now)) {
            return true;
        }

        return $lifetimeMinutes !== null && $lifetimeMinutes > 0 && $createdAt !== null
            && Carbon::parse($createdAt)->lte($now->copy()->subMinutes($lifetimeMinutes));
    }
}
