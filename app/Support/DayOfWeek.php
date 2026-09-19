<?php

namespace App\Support;

// Sorting a week into calendar order rather than insertion order. MySQL's
// FIELD() would do this in one call but has no PostgreSQL equivalent, so the
// ordering is expressed as a CASE ladder — portable, and still a single
// indexless sort over the at-most-seven rows these queries return.
//
// ORDER matches the day_of_week enum on both branch_schedules and
// staff_schedules; the two must stay in step.
class DayOfWeek
{
    public const ORDER = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
    ];

    // Pair with self::ORDER as the bindings:
    //     ->orderByRaw(DayOfWeek::calendarOrder(), DayOfWeek::ORDER)
    // Anything unrecognised sorts last rather than silently leading.
    public static function calendarOrder(string $column = 'day_of_week'): string
    {
        $cases = '';

        foreach (array_keys(self::ORDER) as $position) {
            $cases .= " WHEN ? THEN {$position}";
        }

        return sprintf('CASE %s%s ELSE %d END', $column, $cases, count(self::ORDER));
    }
}
