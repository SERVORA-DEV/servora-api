<?php

namespace App\Support\Visits;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

// When a visit from an earlier day counts as left open ("stale").
//
// A spa can run a little past midnight (a service that started at 23:30), so
// the day is not over at 00:00: until GRACE_HOURS after it, yesterday is still
// the business day. A visit dated before the business day that is still booked,
// checked in or in service was never closed — nothing on it can be run any more,
// it must not hold up today's staff, rooms or vouchers, and DayCloser closes it.
class StaleVisit
{
    public const GRACE_HOURS = 2;

    /** The business day it is now (Y-m-d): until 02:00 it is still the day before. */
    public static function today(?Carbon $now = null): string
    {
        return ($now ?? now())->copy()->subHours(self::GRACE_HOURS)->toDateString();
    }

    /** Whether a visit dated $date has been left open past its day. */
    public static function isStale(string|\DateTimeInterface $date, ?Carbon $now = null): bool
    {
        return Carbon::parse($date)->toDateString() < self::today($now);
    }

    /**
     * The refusal for working on a visit left open from an earlier day (422), or null when it can
     * be worked on: no check-in, assigning, starting, completing or adding. Marking it a no-show,
     * cancelling it and moving it stay open, they settle it. DayCloser closes it by itself.
     */
    public static function refusal(Appointment $appointment): ?JsonResponse
    {
        if (! in_array($appointment->status, [AppointmentStatus::Scheduled->value, AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value], true)
            || ! self::isStale($appointment->appointment_date)) {
            return null;
        }

        return response()->json(['message' => 'This visit is from a previous day that was never closed, so it can no longer be changed here. It is closed automatically.'], 422);
    }
}
