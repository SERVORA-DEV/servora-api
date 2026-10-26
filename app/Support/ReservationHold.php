<?php

namespace App\Support;

use Carbon\CarbonInterface;

// A reservation that hasn't arrived yet is held for a while after its booked
// time — the grace period (Business Defaults → Missed bookings → "Hold a
// reservation for", booking_defaults.reservation_grace_minutes):
//
//  - Its staff can't be started on another client's service that would run
//    into the booking (AppointmentService::startServiceInternal), until the
//    reservation is cancelled or the grace period is over.
//  - A client who arrives in time keeps their booked place in the line; one who
//    arrives later joins at the back (AppointmentService::checkIn).
//
// Pure time arithmetic, so it can be tested without a database.
final class ReservationHold
{
    public const DEFAULT_GRACE_MINUTES = 15;

    /** When the hold on a reservation booked at $bookedStart ends. */
    public static function endsAt(CarbonInterface $bookedStart, int $graceMinutes): CarbonInterface
    {
        return $bookedStart->copy()->addMinutes(max(0, $graceMinutes));
    }

    /**
     * Whether starting a service of $lengthMinutes now, with staff held for a
     * reservation booked at $bookedStart, is refused: the hold is still on and
     * the service would still be running at the booked time.
     */
    public static function blocks(CarbonInterface $bookedStart, int $graceMinutes, CarbonInterface $now, int $lengthMinutes): bool
    {
        if ($now->gt(self::endsAt($bookedStart, $graceMinutes))) {
            return false;
        }

        return $now->copy()->addMinutes(max(1, $lengthMinutes))->gt($bookedStart);
    }

    /**
     * A reservation's place in the line when it checks in: arriving early or
     * on time, its booked time — so whoever is booked first is ahead, whenever
     * they walked in (booked 4:20 and here at 4:10 goes before booked 4:30 and
     * here at 4:00). Arriving after the grace period is now — the back.
     */
    /**
     * How a reservation arrives against its booked time: before it ('early'),
     * from it to the end of the grace period ('on_time'), or after ('late').
     * The front desk is asked where an early or late one joins the line
     * (AppointmentService::checkIn); an on-time one simply keeps its place.
     */
    public static function arrival(CarbonInterface $bookedStart, int $graceMinutes, CarbonInterface $now): string
    {
        if ($now->lt($bookedStart)) {
            return 'early';
        }

        return $now->gt(self::endsAt($bookedStart, $graceMinutes)) ? 'late' : 'on_time';
    }

    public static function queuedAt(CarbonInterface $bookedStart, int $graceMinutes, CarbonInterface $now): CarbonInterface
    {
        if ($now->gt(self::endsAt($bookedStart, $graceMinutes))) {
            return $now->copy();
        }

        return $bookedStart->copy();
    }
}
