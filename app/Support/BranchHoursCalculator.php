<?php

namespace App\Support;

use App\Models\BranchSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Computes a branch's "open now / closes at" display state from its
// BranchSchedule rows, for the public branch-detail endpoint. Mirrors the
// same conventions already established for booking-time validation in
// AppointmentAvailabilityService::branchIsOpen() (no schedule row for a day,
// or a row with a blank opening/closing time, means "open"; closing_time <=
// opening_time means the branch closes the following day; two equal times
// means open 24 hours) — those aren't directly reusable here since that
// method checks a specific requested date/time rather than "right now" and
// doesn't build a display-ready weekly table.
class BranchHoursCalculator
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function resolve(Collection $schedules, Carbon $now): array
    {
        $byDay = $schedules->keyBy('day_of_week');
        $today = $byDay->get($now->format('l'));

        [$isOpenNow, $closesAt, $opensAt] = self::currentStatus($today, $now);

        $weekly = collect(self::DAYS)->map(function (string $day) use ($byDay) {
            $schedule = $byDay->get($day);
            $closed = $schedule ? (bool) $schedule->is_closed : false;

            return [
                'day' => $day,
                'closed' => $closed,
                'open' => $closed ? null : self::formatTime($schedule?->opening_time),
                'close' => $closed ? null : self::formatTime($schedule?->closing_time),
            ];
        })->all();

        return [
            'is_open_now' => $isOpenNow,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'weekly' => $weekly,
        ];
    }

    // Returns [isOpenNow, closesAt, opensAt]. "opens_at" when currently
    // closed is a same-day simplification (today's configured opening
    // time) rather than a full lookahead to the next open day/time.
    private static function currentStatus(?BranchSchedule $today, Carbon $now): array
    {
        if (! $today) {
            return [true, null, null];
        }

        if ($today->is_closed) {
            return [false, null, null];
        }

        if (! $today->opening_time || ! $today->closing_time) {
            return [true, null, null];
        }

        $date = $now->toDateString();
        $open = Carbon::parse("{$date} {$today->opening_time}");
        $close = Carbon::parse("{$date} {$today->closing_time}");

        if ($open->eq($close)) {
            return [true, null, null];
        }

        $inWindow = $close->gt($open)
            ? $now->gte($open) && $now->lt($close)
            : $now->gte($open) || $now->lt($close);

        if ($inWindow) {
            return [true, self::formatTime($today->closing_time), null];
        }

        return [false, null, self::formatTime($today->opening_time)];
    }

    private static function formatTime(?string $time): ?string
    {
        return $time ? Carbon::parse($time)->format('g:i A') : null;
    }
}
