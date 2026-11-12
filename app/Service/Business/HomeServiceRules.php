<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Service\Client\BookingPolicy;

// Home service — the therapist goes to the client. One place for the rules,
// shared by the front desk's and the client app's booking paths
// (AppointmentService):
//
//   • the branch offers it (Business Defaults → Home service, or the
//     branch's own rule);
//   • the client's address is pinned, and the pin is inside the branch's
//     service area (straight-line distance from the branch's own pin);
//   • every service on the booking is one the owner marked "Available for
//     home service";
//   • whoever does the visit is staff the owner marked "Available for home
//     service" (Staff::takesHomeService) — and the branch offers it only
//     while it has someone like that;
//   • the services add up to at least the branch's minimum, when it has one
//     (the client app is refused; the front desk is only warned).
//
// New visits use home-service catalog prices and the existing home commission
// policy. Historical flat fees remain on the appointments that recorded them.
class HomeServiceRules
{
    public const IN_SPA = 'In Spa';

    public const HOME = 'Home Service';

    private const EARTH_RADIUS_KM = 6371.0088;

    public static function isHome(?array $payload): bool
    {
        return ($payload['service_location'] ?? self::IN_SPA) === self::HOME;
    }

    // What a branch offers, for the apps: on/off, the fee, the area and the
    // travel time each way (0 = none), which the staff are kept for too.
    public static function offer(SpaBranch $branch): array
    {
        $policy = BookingPolicy::for($branch);
        $hasPin = $branch->latitude !== null && $branch->longitude !== null;
        // No pin on the branch = no way to measure the area.
        $on = $policy['home_service_enabled'] && $hasPin;
        // Switched on, but nobody can be sent out: not bookable (nothing to ask when it is off).
        $hasStaff = ! $on || self::hasStaff($branch);

        return [
            'enabled' => $on && $hasStaff,
            'staff_available' => $hasStaff,
            'fee' => 0.0, // Legacy fees remain only on existing appointments.
            'radius_km' => (int) $policy['home_service_radius_km'],
            'travel_minutes' => (int) $policy['home_service_travel_minutes'],
        ];
    }

    // ── Travel time ──────────────────────────────────────────────────────
    // A home visit takes its staff away for the trip there and back: they are kept from the travel
    // time before it starts until the travel time after it ends. Booked at 10:00 PM, an hour of
    // services and 20 minutes of travel keep them from 9:40 PM to 11:20 PM. Every staff clash check
    // (AppointmentAvailabilityService, BookingSlotService) reads bookings through these.

    private const TRAVEL = 'home-service.travel';

    /** The branch's travel time each way, in minutes (remembered for the request). */
    public static function travelMinutes(int $branchId): int
    {
        if (! app()->bound(self::TRAVEL)) {
            app()->scoped(self::TRAVEL, fn () => new \ArrayObject());
        }
        $memo = app(self::TRAVEL);
        if (! isset($memo[$branchId])) {
            $branch = SpaBranch::find($branchId);
            $memo[$branchId] = $branch ? (int) (BookingPolicy::for($branch)['home_service_travel_minutes'] ?? 0) : 0;
        }

        return $memo[$branchId];
    }

    /**
     * The time a booking keeps its staff: its time to its end, widened by the travel time on both
     * sides for a home visit.
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    public static function span(bool $home, int $branchId, \Carbon\Carbon $start, int $minutes): array
    {
        $travel = $home ? self::travelMinutes($branchId) : 0;

        return [$start->copy()->subMinutes($travel), $start->copy()->addMinutes($minutes + $travel)];
    }

    /**
     * A time and length to check a booking against, widened for a home visit: the time it starts
     * keeping its staff (H:i) and for how long. For callers that take a start time and minutes.
     *
     * @return array{0: string, 1: int}
     */
    public static function widen(bool $home, int $branchId, string $date, string $time, int $minutes): array
    {
        $travel = $home ? self::travelMinutes($branchId) : 0;
        if ($travel <= 0) {
            return [$time, $minutes];
        }
        $from = \Carbon\Carbon::parse("{$date} " . substr($time, 0, 5))->subMinutes($travel);
        // Not before midnight: the day's other bookings are what it is checked against.
        $from = $from->format('Y-m-d') === $date ? $from : \Carbon\Carbon::parse("{$date} 00:00");

        return [$from->format('H:i'), $minutes + 2 * $travel];
    }

    /**
     * The same for a booking that exists, with its own length ($minutes, the services' total).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    public static function blockedWindow(Appointment $appointment, int $minutes): array
    {
        $start = \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . substr((string) $appointment->appointment_time, 0, 5));
        if (! $appointment->isHomeService()) {
            return self::span(false, (int) $appointment->spa_branch_id, $start, $minutes);
        }

        // A home visit by its actual times when known: from when the staff arrived if later than
        // booked ($minutes already counts any extension), and once its services are all done,
        // until the last one ended. Then the trip back.
        $travel = self::travelMinutes((int) $appointment->spa_branch_id);
        $arrived = $appointment->check_in_at ? \Carbon\Carbon::parse($appointment->check_in_at) : null;
        $from = $arrived && $arrived->gt($start) ? $arrived : $start;
        $end = $from->copy()->addMinutes($minutes);
        if ($appointment->relationLoaded('services')) {
            $open = $appointment->services->reject(fn ($s) => $s->status === 'Cancelled');
            if ($open->isNotEmpty() && $open->every(fn ($s) => $s->status === 'Completed')) {
                $done = $open->flatMap(fn ($s) => $s->relationLoaded('therapistAssignments') ? $s->therapistAssignments->pluck('completed_at') : collect())->filter()->max();
                $end = $done ? \Carbon\Carbon::parse($done) : $end;
            }
        }

        return [$start->copy()->subMinutes($travel), $end->copy()->addMinutes($travel)];
    }

    /**
     * The trips back from home visits already done: each staff member who finished their part of a
     * home visit and is still on the way back to the spa, with when they are back (completed +
     * travel). Only when nothing else of that visit is still theirs to do.
     *
     * @param  array<int, int>  $staffIds
     * @return array<int, array{from: \Carbon\Carbon, to: \Carbon\Carbon}> staff id => the trip back
     */
    public static function returnTrips(array $staffIds, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        if (! $staffIds) {
            return [];
        }
        $rows = \App\Models\TherapistAssignment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('assignment_status', ['Completed', 'Assigned', 'In Progress'])
            ->whereHas('appointmentService.appointment', fn ($q) => $q->where('service_location', self::HOME)
                ->whereDate('appointment_date', '>=', $from->copy()->subDay()->toDateString())
                ->whereDate('appointment_date', '<=', $to->toDateString())
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]))
            ->with('appointmentService:id,appointment_id', 'appointmentService.appointment:id,spa_branch_id')
            ->get(['id', 'staff_id', 'appointment_service_id', 'assignment_status', 'completed_at']);

        $trips = [];
        foreach ($rows->groupBy(fn ($r) => $r->staff_id . ':' . $r->appointmentService?->appointment_id) as $visit) {
            // Still doing something of this visit: not on the way back yet.
            if ($visit->contains(fn ($r) => $r->assignment_status !== 'Completed')) {
                continue;
            }
            $done = $visit->pluck('completed_at')->filter()->max();
            if (! $done) {
                continue;
            }
            $back = \Carbon\Carbon::parse($done)->addMinutes(self::travelMinutes((int) $visit->first()->appointmentService->appointment->spa_branch_id));
            $left = \Carbon\Carbon::parse($done);
            if ($left->lt($to) && $back->gt($from)) {
                $staffId = (int) $visit->first()->staff_id;
                if (! isset($trips[$staffId]) || $back->gt($trips[$staffId]['to'])) {
                    $trips[$staffId] = ['from' => $left, 'to' => $back];
                }
            }
        }

        return $trips;
    }

    /** "On the way back from a home visit until 3:30 PM." */
    public static function returningLine(\Carbon\Carbon $back): string
    {
        return 'On the way back from a home visit until ' . $back->format('g:i A');
    }

    /**
     * Checks the place of a home booking against the branch's rules.
     *
     * @return array{ok: bool, reason: ?string, distance_km: ?float, fee: float}
     */
    public static function check(SpaBranch $branch, array $payload): array
    {
        $offer = self::offer($branch);
        $fail = fn (string $reason) => ['ok' => false, 'reason' => $reason, 'distance_km' => null, 'fee' => 0.0];

        if (! $offer['enabled']) {
            return $fail($offer['staff_available']
                ? "{$branch->branch_name} doesn't offer home service."
                : "{$branch->branch_name} has no one available for home service right now.");
        }

        if (trim((string) ($payload['home_address'] ?? '')) === '') {
            return $fail('Please enter the address for the home service.');
        }

        $lat = $payload['home_latitude'] ?? null;
        $lng = $payload['home_longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return $fail('Please pin the address on the map so we can check it is inside the service area.');
        }

        $distance = self::distanceKm((float) $branch->latitude, (float) $branch->longitude, (float) $lat, (float) $lng);
        $radius = $offer['radius_km'];
        if ($radius > 0 && $distance > $radius) {
            return $fail(sprintf(
                'That address is %s km from %s, outside its home-service area of %d km.',
                number_format($distance, 1), $branch->branch_name, $radius,
            ));
        }

        return ['ok' => true, 'reason' => null, 'distance_km' => round($distance, 2), 'fee' => $offer['fee']];
    }

    // Whether anyone at the branch can be sent to a client's home: an active
    // staff member with "Available for home service" on.
    public static function hasStaff(SpaBranch $branch): bool
    {
        return Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->forHomeService()
            ->exists();
    }

    // Why this person can't do a home visit, or null when they can.
    public static function staffRefusal(Staff $staff): ?string
    {
        if ($staff->takesHomeService()) {
            return null;
        }

        $name = trim("{$staff->first_name} {$staff->last_name}") ?: 'This staff member';

        return "{$name} isn't available for home service. Please choose someone who is.";
    }

<<<<<<< HEAD
<<<<<<< HEAD
    // Why a home booking of $minutes is too short for this branch, or null
    // when it is long enough (or there is no minimum). The client app is
    // refused with this; the front desk only sees it as a warning.
    public static function minimumProblem(array $policy, int $minutes): ?string
    {
        $minimum = (int) ($policy['home_service_min_minutes'] ?? 0);

        if ($minimum <= 0 || $minutes >= $minimum) {
            return null;
        }

        return 'Home service at this spa needs at least '.BookingPolicy::formatMinutes($minimum).' of services, and these take '
            .BookingPolicy::formatMinutes($minutes).'. Add a service, or book at the spa.';
    }

=======
>>>>>>> main
=======
>>>>>>> main
    // The columns a home booking stores, once check() has passed.
    public static function attributes(array $payload, array $check): array
    {
        return [
            'service_location' => self::HOME,
            'home_address' => trim((string) $payload['home_address']),
            'home_address_notes' => trim((string) ($payload['home_address_notes'] ?? '')) ?: null,
            'home_latitude' => $payload['home_latitude'],
            'home_longitude' => $payload['home_longitude'],
            'home_distance_km' => $check['distance_km'],
            'home_service_fee' => $check['fee'],
        ];
    }

    /**
     * Names of the services on this booking that can't be done at home —
     * empty when the booking is fine. Run after the services are attached.
     *
     * @return string[]
     */
    public static function ineligibleServices(Appointment $appointment): array
    {
        return $appointment->services()
            ->where('status', '!=', 'Cancelled')
            ->with('serviceVariant.service')
            ->get()
            // An add-on goes wherever its main service goes: it isn't judged on its own.
            ->filter(fn ($line) => ! $line->isAddon() && ! $line->serviceVariant?->service?->home_service_available)
            ->map(fn ($line) => $line->displayName() ?? 'A service')
            ->unique()
            ->values()
            ->all();
    }

    public static function ineligibleMessage(array $names): string
    {
        $list = implode(', ', $names);

        return count($names) === 1
            ? "{$list} isn't available for home service. Remove it, or book it at the spa."
            : "These aren't available for home service: {$list}. Remove them, or book at the spa.";
    }

    // Great-circle (straight-line) distance between two pins, in km.
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Splits one booking's fee between the therapists who did the visit.
     *
     * @param  int[]  $staffIds  therapists with a completed service on the booking
     * @return array<int, float> staff id => share
     */
    public static function splitFee(float $fee, array $staffIds): array
    {
        $staffIds = array_values(array_unique($staffIds));
        if ($fee <= 0 || ! $staffIds) {
            return [];
        }

        return array_fill_keys($staffIds, round($fee / count($staffIds), 2));
    }
}
