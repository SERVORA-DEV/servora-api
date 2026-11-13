<?php

namespace App\Support;

use App\Models\BranchSchedule;

// When a branch can take a booking on one calendar date, as stretches of minutes from that date's
// midnight. A branch open past midnight ("11 AM – 3 AM" on Friday) is open on two dates: Friday's
// own hours run 11:00 AM to midnight, and the rest (12:00 AM – 3:00 AM) belongs to Saturday, as the
// first stretch of Saturday: whether or not Saturday is a day the branch opens at all.
//
// Pure arithmetic over the two schedule rows (the date's own and the day before's) so the rules sit
// in one place and are tested without a database (tests/Unit/BranchOpenWindowsTest).
//
// Hours conventions, the same as everywhere else (AppointmentAvailabilityService::branchIsOpen):
// closing at or before opening closes the next day, and closing at 12:00 AM is the end of that day.
final class BranchOpenWindows
{
    /**
     * @param  ?array{0: int, 1: int}  $fallback  [open, close] in minutes, used when the date has no hours entered; null = no limit then
     * @return array{unlimited: bool, closed: bool, windows: list<array{from: int, to: int, close: int}>}
     *         from/to: the start times that can be booked (to is exclusive, never past midnight);
     *         close: when the stretch really ends, which for tonight's hours is past midnight (1620 = 3 AM tomorrow).
     */
    public static function for(?BranchSchedule $today, ?BranchSchedule $yesterday, ?array $fallback = null): array
    {
        $windows = [];
        $unlimited = false;
        $own = null;

        if (! $today?->is_closed) {
            $open = $today?->opening_time ? self::minutes($today->opening_time) : null;
            $close = $today?->closing_time ? self::minutes($today->closing_time) : null;
            if (($open === null || $close === null) && $fallback) {
                $open ??= $fallback[0];
                $close ??= $fallback[1];
            }

            if ($open === null || $close === null) {
                $unlimited = true;
            } else {
                $end = $close <= $open ? $close + 1440 : $close;
                $own = ['from' => $open, 'to' => min($end, 1440), 'close' => $end];
            }
        }

        // The tail of last night's hours is this morning's first stretch.
        $tail = self::tail($yesterday);
        if ($tail > 0 && ! $unlimited) {
            $to = $own ? min($tail, $own['from']) : $tail;
            if ($to > 0) {
                $windows[] = ['from' => 0, 'to' => $to, 'close' => $tail];
            }
        }
        if ($own && $own['to'] > $own['from']) {
            $windows[] = $own;
        }

        return ['unlimited' => $unlimited, 'closed' => ! $unlimited && ! $windows, 'windows' => $windows];
    }

    /**
     * Minutes after midnight that a day's hours run into the next morning (0 = they end by midnight).
     * "11 AM – 3 AM" is 180; "9 AM – 12 AM" and a closed or unset day are 0.
     */
    public static function tail(?BranchSchedule $row): int
    {
        if (! $row || $row->is_closed || ! $row->opening_time || ! $row->closing_time) {
            return 0;
        }
        $open = self::minutes($row->opening_time);
        $close = self::minutes($row->closing_time);

        // Equal times are open round the clock, not "until the same time tomorrow".
        return $close < $open ? $close : 0;
    }

    /** "09:30" or "09:30:00" as minutes from midnight. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }
}
