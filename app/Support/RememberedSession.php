<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

// "Remember me" on the sign-in page: keep this device signed in.
//
// A plain sign-in's token lives for the lifetime every token has
// (config sanctum.expiration, 30 days from sign-in) and the browser forgets
// its cookie when it closes. A remembered sign-in's token instead gets its
// own end date (expires_at), DAYS ahead, and that date moves forward again
// whenever the device uses it — so a device that comes back at least once a
// year is never asked to sign in again. Signing out on the device deletes the
// token, and with it the remembered device.
//
// The token row is the record of the device: its name ("Chrome on Windows"),
// user_agent, ip_address and last_used_at (see UserService::issueLoginToken).
// Only a remembered sign-in has an expires_at, which is how it is told apart.
//
// Pure — tested in tests/Unit/RememberedSessionTest. Used by
// UserService::issueLoginToken, the Sanctum check in AppServiceProvider, and
// SessionActivity::expired.
class RememberedSession
{
    public const DAYS = 365;

    // Moved forward at most once a day: the date is only rewritten when it
    // is more than this far behind a fresh one, so ordinary requests don't
    // each cost a database write.
    public const REFRESH_AFTER_HOURS = 24;

    /** When a remembered sign-in made (or used) at $now stops working. */
    public static function expiresAt(DateTimeInterface $now): Carbon
    {
        return Carbon::instance($now)->addDays(self::DAYS);
    }

    /** A token with its own end date is a remembered sign-in. */
    public static function isRemembered(DateTimeInterface|string|null $expiresAt): bool
    {
        return $expiresAt !== null;
    }

    /**
     * Whether a remembered sign-in still works: its own end date decides, not
     * the lifetime every plain sign-in has.
     */
    public static function valid(DateTimeInterface|string $expiresAt, DateTimeInterface $now): bool
    {
        return Carbon::parse($expiresAt)->gt(Carbon::instance($now));
    }

    /** Whether using it at $now should push its end date forward. */
    public static function shouldExtend(DateTimeInterface|string $expiresAt, DateTimeInterface $now): bool
    {
        return Carbon::parse($expiresAt)->lt(self::expiresAt($now)->subHours(self::REFRESH_AFTER_HOURS));
    }
}
