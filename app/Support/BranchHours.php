<?php

namespace App\Support;

use App\Models\BranchSchedule;
use Carbon\Carbon;

/**
 * A branch's opening hours for a day, as minutes from midnight, for the places that must keep
 * a staff schedule or a recorded time inside them (the weekly schedule save, a correction).
 *
 * A closing time of exactly midnight means the end of that day (a branch open until 12:00 AM).
 * A day with no hours set, or open round the clock or overnight, has no limit to keep to.
 */
class BranchHours
{
    /** The window on this date, ['closed' => true] for a closed day, or null for "no limit". */
    public static function window(int $branchId, string $date): ?array
    {
        $row = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->first();

        return self::windowOf($row);
    }

    public static function windowOf(?BranchSchedule $row): ?array
    {
        if (! $row) {
            return null;
        }
        if ($row->is_closed) {
            return ['closed' => true];
        }
        if (! $row->opening_time || ! $row->closing_time) {
            return null;
        }

        $open = self::minutes($row->opening_time);
        $close = self::minutes($row->closing_time);

        if ($close === 0) {
            $close = 1440;
        } elseif ($close <= $open) {
            return null;
        }

        return [
            'open' => $open,
            'close' => $close,
            'label' => self::label($open) . ' to ' . self::label($close % 1440),
        ];
    }

    /** "09:30" or "09:30:00" as minutes from midnight. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }

    public static function label(int $minutes): string
    {
        return Carbon::createFromTime(intdiv($minutes, 60) % 24, $minutes % 60)->format('g:i A');
    }
}
