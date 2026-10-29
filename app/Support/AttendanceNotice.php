<?php

namespace App\Support;

use Carbon\Carbon;

// What the branch's managers and the owner read when someone marks, corrects or
// clears a staff member's attendance on the Management page. Pure wording, so it
// is easy to test: the caller passes the record's old and new values (AUDIT_FIELDS:
// status, check_in_at, check_out_at, remarks) and gets the title, the message, or
// null when nothing a reader cares about changed.
final class AttendanceNotice
{
    private const LABELS = ['status' => 'status', 'check_in_at' => 'time in', 'check_out_at' => 'time out', 'remarks' => 'notes'];

    /**
     * @param  array<string, mixed>|null  $old  null for a new record
     * @param  array<string, mixed>|null  $new  null when the record was cleared
     * @return array{0: string, 1: string}|null  [title, message]
     */
    public static function describe(string $actor, string $staffName, string $date, ?array $old, ?array $new): ?array
    {
        $day = Carbon::parse($date)->format('M j');

        if ($new === null) {
            return ['Attendance cleared', "{$actor} cleared {$staffName}'s attendance for {$day}."];
        }

        if ($old === null) {
            $status = $new['status'] ?? null;

            return ['Attendance marked', "{$actor} marked {$staffName} ".($status ? strtolower((string) $status) : 'in')." for {$day}."];
        }

        $changes = [];
        foreach (self::LABELS as $key => $label) {
            $before = self::show($key, $old[$key] ?? null);
            $after = self::show($key, $new[$key] ?? null);
            if ($before !== $after) {
                $changes[] = $key === 'remarks'
                    ? 'notes were updated'
                    : "{$label} was {$before}, now {$after}";
            }
        }

        if (! $changes) {
            return null;
        }

        return ['Attendance changed', "{$actor} changed {$staffName}'s attendance for {$day}: ".implode('; ', $changes).'.'];
    }

    private static function show(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }
        if (($key === 'check_in_at' || $key === 'check_out_at') && ($value instanceof \DateTimeInterface || is_string($value))) {
            return Carbon::parse($value)->format('g:i A');
        }

        return (string) $value;
    }
}
