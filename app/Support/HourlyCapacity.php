<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Service\Client\BookingPolicy;

// How many reservations a branch accepts in one clock hour (Settings →
// Booking → "Bookings per hour"; 0 = no limit). A booking belongs to the
// hour its START falls in: 8:00, 8:34 and 8:55 PM are all the 8 PM hour, so
// the limit is about how many are taken for that hour, not how they are
// spaced inside it.
//
// Only reservations count. A walk-in is here now and is never refused by
// the limit, and does not use up the hour either — a busy walk-in
// afternoon must not block the bookings made ahead.
//
// Pure arithmetic is kept apart from the one query (bookedByHour) so the
// rule is tested without a database (tests/Unit/HourlyCapacityTest).
class HourlyCapacity
{
    // Reservations are placed on the 5-minute marks.
    public const STEP_MINUTES = 5;

    /** The clock hour (0–23) a 'HH:MM' or 'HH:MM:SS' time falls in. */
    public static function hourOf(string $time): int
    {
        return intdiv(BookingBoard::minutes($time), 60);
    }

    /** A time off the 5-minute marks (8:34), or null when it is on one. */
    public static function gridProblem(string $time): ?string
    {
        return BookingBoard::minutes($time) % self::STEP_MINUTES === 0
            ? null
            : 'Please pick a time on a 5-minute mark (for example 8:30 or 8:35).';
    }

    /** Whether an hour that already has $count bookings can take another. */
    public static function isFull(int $limit, int $count): bool
    {
        return $limit > 0 && $count >= $limit;
    }

    /** Why one more booking can't go in this hour, or null. */
    public static function problem(int $limit, int $count): ?string
    {
        return self::isFull($limit, $count)
            ? "That hour is fully booked ({$count} of {$limit}). Please pick another hour."
            : null;
    }

    /**
     * The hours as a list for the clients: how many are booked in each, the
     * limit, whether it is full. Empty when there is no limit.
     *
     * @param  array<int, int>  $counts  bookings per hour
     * @param  list<int>  $hours  the hours the day is offered in
     * @return list<array{hour: string, booked: int, limit: int, full: bool}>
     */
    public static function summary(int $limit, array $counts, array $hours): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = [];
        foreach (array_values(array_unique($hours)) as $hour) {
            $booked = $counts[$hour] ?? 0;
            $rows[] = [
                'hour' => sprintf('%02d:00', $hour % 24),
                'booked' => $booked,
                'limit' => $limit,
                'full' => self::isFull($limit, $booked),
            ];
        }

        return $rows;
    }

    /** The branch's limit for its hours (0 = none). */
    public static function limitFor(SpaBranch $branch): int
    {
        return (int) (BookingPolicy::for($branch)['max_bookings_per_hour'] ?? 0);
    }

    /**
     * Reservations already taken on the date, per hour. $excludeId is the
     * booking being moved, so moving it inside its own hour is not "full".
     *
     * @return array<int, int> hour => count
     */
    public static function bookedByHour(int $branchId, string $date, ?int $excludeId = null): array
    {
        $counts = [];
        Appointment::where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $date)
            ->where('appointment_type', 'Reservation')
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->pluck('appointment_time')
            ->each(function ($time) use (&$counts) {
                $hour = self::hourOf((string) $time);
                $counts[$hour] = ($counts[$hour] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * Why a reservation at this date and time can't be taken (the hour is
     * full, or the time is off the 5-minute marks), or null. Costs no query
     * for a branch with no limit.
     */
    public static function problemFor(SpaBranch $branch, string $date, string $time, ?int $excludeId = null): ?string
    {
        if ($off = self::gridProblem($time)) {
            return $off;
        }

        $limit = self::limitFor($branch);
        if ($limit <= 0) {
            return null;
        }

        $counts = self::bookedByHour($branch->id, $date, $excludeId);

        return self::problem($limit, $counts[self::hourOf($time)] ?? 0);
    }
}
