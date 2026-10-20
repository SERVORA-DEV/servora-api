<?php

namespace App\Support;

// What lateness and absences take off a staff member's earnings (Staff
// Policies → Deductions): for each day they were Late, either a fixed amount
// or an hourly rate for the minutes they were late (30 minutes at ₱60 an
// hour = ₱30); and a fixed amount for each day they were Absent from a
// scheduled shift. Leave, holidays, fill-ins
// and days off never count. Each day is charged at the rate in force on that
// day (App\Models\StaffPenaltyRate::history), so a change to the policy only
// reaches days from then on.
//
// The pay period's earnings never go below zero: what the penalties would
// take past that is shown as capped, not owed.
//
// Pure — tested in tests/Unit/AttendancePenaltyTest.
class AttendancePenalty
{
    public const LATE = 'Late';
    public const ABSENT = 'Absent';

    /**
     * The rate in force on a date: the newest one starting on or before it.
     *
     * @param  array<int, array{from: string}>  $history  oldest first
     */
    public static function rateOn(array $history, string $date): ?array
    {
        $found = null;
        foreach ($history as $rate) {
            if ($rate['from'] <= $date) {
                $found = $rate;
            }
        }

        return $found ?? ($history[0] ?? null);
    }

    /**
     * One penalty per late or absent day.
     *
     * @param  iterable<array{date: string, status: ?string, late_minutes?: ?int}>  $days  one staff member's resolved days
     * @return array<int, array{date: string, kind: string, amount: float, minutes: ?int}>
     */
    public static function forDays(iterable $days, array $history): array
    {
        $out = [];
        foreach ($days as $day) {
            $kind = match ($day['status'] ?? null) {
                self::LATE => 'late',
                self::ABSENT => 'absent',
                default => null,
            };
            if (! $kind) {
                continue;
            }
            $rate = self::rateOn($history, $day['date']);
            if (! $rate || ! $rate[$kind . '_enabled'] || (float) $rate[$kind . '_amount'] <= 0) {
                continue;
            }
            $minutes = $kind === 'late' ? max(0, (int) ($day['late_minutes'] ?? 0)) : null;
            $amount = (float) $rate[$kind . '_amount'];
            if ($kind === 'late' && ($rate['late_mode'] ?? 'day') === 'hour') {
                // By the hour: only the minutes they were actually late.
                $amount = $amount * $minutes / 60;
                if ($amount <= 0) {
                    continue;
                }
            }
            $out[] = ['date' => $day['date'], 'kind' => $kind, 'amount' => round($amount, 2), 'minutes' => $minutes];
        }

        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $out;
    }

    /**
     * What is left after the penalties, never below zero.
     *
     * @return array{penalties: float, net: float, capped: float}
     */
    public static function settle(float $earned, float $penalties): array
    {
        $earned = round(max(0.0, $earned), 2);
        $penalties = round(max(0.0, $penalties), 2);
        $net = round(max(0.0, $earned - $penalties), 2);

        return ['penalties' => $penalties, 'net' => $net, 'capped' => round($penalties - ($earned - $net), 2)];
    }
}
