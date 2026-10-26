<?php

namespace App\Support;

use Carbon\Carbon;

// When a visit from an earlier day counts as left open ("stale").
//
// A spa can run a little past midnight (a service that started at 23:30), so
// the day is not over at 00:00: until GRACE_HOURS after it, yesterday is still
// the business day. A visit dated before the business day that is still booked,
// checked in or in service was never closed — nothing on it can be run any more,
// it must not hold up today's staff, rooms or vouchers, and DayCloser closes it.
class StaleVisit
{
    public const GRACE_HOURS = 2;

    /** The business day it is now (Y-m-d): until 02:00 it is still the day before. */
    public static function today(?Carbon $now = null): string
    {
        return ($now ?? now())->copy()->subHours(self::GRACE_HOURS)->toDateString();
    }

    /** Whether a visit dated $date has been left open past its day. */
    public static function isStale(string|\DateTimeInterface $date, ?Carbon $now = null): bool
    {
        return Carbon::parse($date)->toDateString() < self::today($now);
    }
}
