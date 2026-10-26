<?php

namespace App\Support;

use App\Models\BranchSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

// A branch's working day runs from when it opens to when it opens next, not
// from midnight to midnight. Staff on the floor at 1 AM after a late night are
// still on the day they came in for; that day ends when the branch opens the
// next morning (AttendanceRollover then times them out and back in for the new
// day at that same moment). Nobody is timed out at closing time or at the end
// of their shift.
//
// The hours are the branch's weekly schedule (Branch Settings → Operations). A
// day it is closed is skipped: the day before runs on until it next opens. A
// branch with no hours set falls back to the calendar day.
//
// The pure part (opensAt, current, nextOpening over a given week) is tested in
// tests/Unit/BusinessDayTest; forBranch() reads the schedule once per request.
final class BusinessDay
{
    /** How far back or ahead a working day is looked for. */
    private const SPAN_DAYS = 8;

    /**
     * When the branch opens on a date, or null when it doesn't open that day.
     *
     * @param  array<string, array{opening_time: ?string, is_closed: bool}>  $week  by day name (Monday…)
     */
    public static function opensAt(array $week, string $date): ?Carbon
    {
        $day = $week[Carbon::parse($date)->format('l')] ?? null;
        if (! $day || $day['is_closed'] || ! $day['opening_time']) {
            return null;
        }

        return Carbon::parse("{$date} " . substr($day['opening_time'], 0, 5));
    }

    /** The working day it is now: the latest day whose opening has come. */
    public static function current(array $week, CarbonInterface $now): string
    {
        for ($i = 0; $i <= self::SPAN_DAYS; $i++) {
            $date = $now->copy()->subDays($i)->toDateString();
            $opens = self::opensAt($week, $date);
            if ($opens && $now->gte($opens)) {
                return $date;
            }
        }

        return $now->toDateString();
    }

    /** When the working day that started on $date ends: the branch's next opening. */
    public static function nextOpening(array $week, string $date): ?Carbon
    {
        for ($i = 1; $i <= self::SPAN_DAYS; $i++) {
            $opens = self::opensAt($week, Carbon::parse($date)->addDays($i)->toDateString());
            if ($opens) {
                return $opens;
            }
        }

        return null;
    }

    /**
     * Whether the branch is open at a moment: inside today's hours, or still inside
     * yesterday's for a branch open past midnight. The same conventions as booking
     * (AppointmentAvailabilityService::branchIsOpen): no hours set for a day means
     * open, equal times mean open all day, closing at or before opening means it
     * closes the next day.
     *
     * @param  array<string, array{opening_time: ?string, closing_time?: ?string, is_closed: bool}>  $week
     */
    public static function isOpen(array $week, CarbonInterface $at): bool
    {
        foreach ([0, 1] as $back) {
            $date = $at->copy()->subDays($back)->toDateString();
            $day = $week[Carbon::parse($date)->format('l')] ?? null;
            $close = $day['closing_time'] ?? null;
            if (! $day || ! $day['opening_time'] || ! $close) {
                if ($back === 0 && ! ($day['is_closed'] ?? false)) {
                    return true;
                }
                continue;
            }
            if ($day['is_closed']) {
                continue;
            }
            $opens = Carbon::parse("{$date} " . substr($day['opening_time'], 0, 5));
            $closes = Carbon::parse("{$date} " . substr($close, 0, 5));
            if ($closes->lte($opens)) {
                $closes->addDay();
            }
            if ($at->gte($opens) && $at->lt($closes)) {
                return true;
            }
        }

        return false;
    }

    /** When the branch next opens after a moment, or null when it has no hours ahead. */
    public static function opensNext(array $week, CarbonInterface $after): ?Carbon
    {
        for ($i = 0; $i <= self::SPAN_DAYS; $i++) {
            $opens = self::opensAt($week, $after->copy()->addDays($i)->toDateString());
            if ($opens && $opens->gt($after)) {
                return $opens;
            }
        }

        return null;
    }

    /**
     * The attendance date to read for $date at a branch: today's calendar date
     * means the working day it is now (yesterday's, before the branch opens);
     * any other date is itself.
     */
    public static function attendanceDate(?int $branchId, string $date, ?CarbonInterface $now = null): string
    {
        $now ??= now();

        return $branchId && $date === $now->toDateString() ? self::current(self::week($branchId), $now) : $date;
    }

    /** The branch's weekly hours, read once per request. */
    public static function week(int $branchId): array
    {
        $memo = self::memo();

        return $memo[$branchId] ??= BranchSchedule::where('spa_branch_id', $branchId)
            ->get(['day_of_week', 'opening_time', 'closing_time', 'is_closed'])
            ->mapWithKeys(fn (BranchSchedule $s) => [$s->day_of_week => ['opening_time' => $s->opening_time, 'closing_time' => $s->closing_time, 'is_closed' => (bool) $s->is_closed]])
            ->all();
    }

    /** The working day it is now at a branch. */
    public static function forBranch(?int $branchId, ?CarbonInterface $now = null): string
    {
        $now ??= now();

        return $branchId ? self::current(self::week($branchId), $now) : $now->toDateString();
    }

    private static function memo(): \ArrayObject
    {
        if (! app()->bound('servora.business_day')) {
            app()->scoped('servora.business_day', fn () => new \ArrayObject());
        }

        return app('servora.business_day');
    }
}
