<?php

namespace App\Support;

// An attendance day stays as it turned out (App\Support\History).
//
// Two moments fix it:
//   - the check-in fixes the shift the person was on and how late they were;
//   - the check-out fixes the hours worked, the overtime and the day's result.
// Changing the late threshold, the overtime switch or a closing time later
// applies to days still to come.
//
// Pure: results in, results out — tested in tests/Unit/AttendanceKeptTest.
// App\Service\Business\AttendanceStatusCalculator works the day out, stores
// what toKeep() gives, and reads it back through apply().
class AttendanceKept
{
    /**
     * What to store for the day, from the result just worked out.
     *
     * @param  array  $live  AttendanceStatusCalculator's result under today's settings
     * @param  ?array{late_minutes: ?int, scheduled_start: ?string, scheduled_end: ?string}  $fromCheckIn
     *         what the check-in already fixed (null: take it from $live — a new check-in, or a corrected one)
     * @return array{status: ?string, late_minutes: ?int, work_minutes: ?int, overtime_minutes: ?int, scheduled_start: ?string, scheduled_end: ?string}
     */
    public static function toKeep(array $live, ?array $fromCheckIn = null): array
    {
        $late = $fromCheckIn ? $fromCheckIn['late_minutes'] : ($live['late_minutes'] ?? null);

        return [
            'status' => self::withLateness($live['status'] ?? null, $late),
            'late_minutes' => $late,
            'work_minutes' => $live['work_minutes'] ?? null,
            'overtime_minutes' => $live['overtime_minutes'] ?? null,
            'scheduled_start' => $fromCheckIn ? $fromCheckIn['scheduled_start'] : ($live['scheduled_start'] ?? null),
            'scheduled_end' => $fromCheckIn ? $fromCheckIn['scheduled_end'] : ($live['scheduled_end'] ?? null),
        ];
    }

    /**
     * The day as it should be shown: what was kept, over what today's
     * settings would say.
     *
     * @param  array  $live  the result under today's settings
     * @param  ?array{status: ?string, late_minutes: ?int, work_minutes: ?int, overtime_minutes: ?int, scheduled_start: ?string, scheduled_end: ?string, checked_in: bool, checked_out: bool}  $kept
     */
    public static function apply(array $live, ?array $kept): array
    {
        if ($kept === null || $kept['status'] === null) {
            return $live;
        }

        // Checked out: the whole day is as it was.
        if ($kept['checked_in'] && $kept['checked_out'] && $kept['work_minutes'] !== null) {
            return [
                'status' => $kept['status'],
                'late_minutes' => $kept['late_minutes'],
                'work_minutes' => $kept['work_minutes'],
                'overtime_minutes' => (int) $kept['overtime_minutes'],
                'scheduled_start' => $kept['scheduled_start'],
                'scheduled_end' => $kept['scheduled_end'],
                'missing_check_in' => false,
                'missing_check_out' => false,
            ] + $live;
        }

        // Checked in, still on the floor (or never checked out): the shift
        // and the lateness are as they were; the rest is still open.
        if ($kept['checked_in']) {
            return [
                'status' => self::withLateness($live['status'] ?? null, $kept['late_minutes']),
                'late_minutes' => $kept['late_minutes'],
                'scheduled_start' => $kept['scheduled_start'],
                'scheduled_end' => $kept['scheduled_end'],
            ] + $live;
        }

        // No check-in (on leave, a holiday, marked absent): nothing here
        // depends on a setting.
        return $live;
    }

    // "Present" or "Late" follows the lateness that was kept; every other
    // result (Fill In, Left Early, Incomplete, On Leave…) stands as it is.
    private static function withLateness(?string $status, ?int $lateMinutes): ?string
    {
        if ($status === 'Present' || $status === 'Late') {
            return ($lateMinutes ?? 0) > 0 ? 'Late' : 'Present';
        }

        return $status;
    }
}
