<?php

namespace App\Support;

// One day at a branch as the front desk needs it when booking: for each
// start time, whether each therapist can take a visit of the given length,
// and whether anyone at all can.
//
// Pure arithmetic over plain values — every time is minutes from midnight of
// the day in question — so the rules sit in one place and are tested without
// a database (tests/Unit/BookingBoardTest). App\Service\Business\
// BookingBoardService loads the day and calls build().
//
// The same stances as the rest of booking: a therapist with no schedule
// entered works whenever the branch is open; a booking with nobody assigned
// still takes one of the free therapists.
class BookingBoard
{
    // A therapist at one start time.
    public const FREE = 'free';
    public const BOOKED = 'booked';      // already has a client then
    public const OFF = 'off';            // not on shift, day off, or on leave
    public const PAST = 'past';          // today, and the time has gone
    // "Any therapist" at one start time.
    public const FULL = 'full';          // every therapist on shift is taken
    // Either row: the hour already has as many reservations as the spa takes
    // in an hour (HourlyCapacity).
    public const HOUR_FULL = 'hour_full';
    // Either row: sooner than the spa's notice ("book at least N hours ahead").
    public const TOO_SOON = 'too_soon';
    // Either row: the visit would still be running when the branch closes.
    public const PAST_CLOSING = 'past_closing';

    public const DAY_WORKING = 'working';
    public const DAY_OFF = 'day_off';
    public const DAY_ON_LEAVE = 'on_leave';

    /**
     * @param  array{open: int, close: int, closures: list<array{0: int, 1: int}>}  $day
     * @param  list<array{id: int, uuid: string, on_leave: bool, has_schedule: bool, shifts: list<array{start: int, end: int}>}>  $therapists
     * @param  list<array{start: int, end: int, staff: list<int>}>  $busy  the day's open bookings; staff = who is on them (empty = nobody yet)
     * @param  ?int  $now  minutes from midnight when the day is today, else null
     * @param  int  $hourLimit  reservations the spa takes in one clock hour (0 = no limit)
     * @param  array<int, int>  $hourCounts  reservations already taken, per clock hour
     * @param  ?int  $soonBefore  minutes from midnight of the day: starts before it are sooner than the spa's notice and can't be booked; null = no notice needed
     * @return array{slots: list<array{time: string, state: string, free_therapists: int}>, therapists: list<array<string, mixed>>, hours: list<array{hour: string, booked: int, limit: int, full: bool}>}
     */
    public static function build(array $day, array $therapists, array $busy, int $duration, int $step, ?int $now = null, int $hourLimit = 0, array $hourCounts = [], ?int $soonBefore = null): array
    {
        $duration = max($duration, 1);
        $step = max($step, 5);

        $starts = [];
        for ($t = $day['open']; $t < $day['close']; $t += $step) {
            // The branch's own rule is about the start (the same check the
            // booking itself makes): not into a closure.
            if (self::overlapsAny($day['closures'], $t, $t + $duration)) {
                continue;
            }
            $starts[] = $t;
        }

        // A start in an hour that is already full can't be booked, for anyone.
        $hourFull = fn (int $t) => HourlyCapacity::isFull($hourLimit, $hourCounts[intdiv($t, 60)] ?? 0);

        $rows = [];
        foreach ($therapists as $therapist) {
            $mine = array_values(array_filter($busy, fn ($b) => in_array($therapist['id'], $b['staff'], true)));
            $slots = [];
            $nextFree = null;
            foreach ($starts as $t) {
                $state = self::therapistState($therapist, $mine, $t, $t + $duration, $now);
                if ($state === self::FREE && $t + $duration > $day['close']) {
                    $state = self::PAST_CLOSING;
                } elseif ($state === self::FREE && $soonBefore !== null && $t < $soonBefore) {
                    $state = self::TOO_SOON;
                } elseif ($state === self::FREE && $hourFull($t)) {
                    $state = self::HOUR_FULL;
                }
                if ($state === self::FREE) {
                    $nextFree ??= $t;
                }
                $slots[] = ['time' => self::clock($t), 'state' => $state];
            }

            $shifts = $therapist['shifts'];
            $working = ! $therapist['on_leave'] && (! $therapist['has_schedule'] || $shifts);
            $rows[] = [
                'uuid' => $therapist['uuid'],
                'day' => $therapist['on_leave'] ? self::DAY_ON_LEAVE : ($working ? self::DAY_WORKING : self::DAY_OFF),
                'shift_start' => $working && $shifts ? self::clock(min(array_column($shifts, 'start'))) : null,
                'shift_end' => $working && $shifts ? self::clock(max(array_column($shifts, 'end'))) : null,
                'booked_count' => count($mine),
                'busy' => array_map(fn ($b) => ['start' => self::clock($b['start']), 'end' => self::clock($b['end'])], $mine),
                'next_free' => $nextFree === null ? null : self::clock($nextFree),
                'slots' => $slots,
            ];
        }

        // Anyone at all: the therapists free then, less the bookings at that
        // time that still have nobody on them (each will need one).
        $any = [];
        foreach ($starts as $i => $t) {
            $free = count(array_filter($rows, fn ($row) => $row['slots'][$i]['state'] === self::FREE));
            $unassigned = count(array_filter($busy, fn ($b) => ! $b['staff'] && $t < $b['end'] && $t + $duration > $b['start']));
            $left = max($free - $unassigned, 0);
            $any[] = [
                'time' => self::clock($t),
                'state' => $now !== null && $t < $now
                    ? self::PAST
                    : ($t + $duration > $day['close']
                        ? self::PAST_CLOSING
                        : ($soonBefore !== null && $t < $soonBefore
                        ? self::TOO_SOON
                        : ($hourFull($t)
                        ? self::HOUR_FULL
                        // No therapists on record: nothing to count, so nothing is full.
                        : (! $therapists || $left > 0 ? self::FREE : self::FULL)))),
                'free_therapists' => $left,
            ];
        }

        return [
            'slots' => $any,
            'therapists' => $rows,
            'hours' => HourlyCapacity::summary($hourLimit, $hourCounts, array_map(fn (int $t) => intdiv($t, 60), $starts)),
        ];
    }

    private static function therapistState(array $therapist, array $mine, int $start, int $end, ?int $now): string
    {
        if ($now !== null && $start < $now) {
            return self::PAST;
        }
        if ($therapist['on_leave']) {
            return self::OFF;
        }

        if ($therapist['has_schedule']) {
            $fits = false;
            foreach ($therapist['shifts'] as $shift) {
                if ($start >= $shift['start'] && $end <= $shift['end']) {
                    $fits = true;
                    break;
                }
            }
            if (! $fits) {
                return self::OFF;
            }
        }

        foreach ($mine as $b) {
            if ($start < $b['end'] && $end > $b['start']) {
                return self::BOOKED;
            }
        }

        return self::FREE;
    }

    private static function overlapsAny(array $windows, int $start, int $end): bool
    {
        foreach ($windows as $w) {
            if ($start < $w[1] && $end > $w[0]) {
                return true;
            }
        }

        return false;
    }

    /** 'HH:MM' for minutes from midnight (a time past midnight wraps: 1500 → 01:00). */
    public static function clock(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Minutes from midnight for 'HH:MM' or 'HH:MM:SS'. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }
}
