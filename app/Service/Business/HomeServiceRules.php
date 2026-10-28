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
// A flat fee (the same rules) is added to the booking and goes to the
// therapist(s) who do the visit — see CommissionCalculator.
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
    // least service time a home visit needs (0 = no minimum).
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
            'fee' => (float) $policy['home_service_fee'],
            'radius_km' => (int) $policy['home_service_radius_km'],
            'min_minutes' => (int) $policy['home_service_min_minutes'],
        ];
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
                "That address is %s km from %s, outside its home-service area of %d km.",
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

    // Why a home booking of $minutes is too short for this branch, or null
    // when it is long enough (or there is no minimum). The client app is
    // refused with this; the front desk only sees it as a warning.
    public static function minimumProblem(array $policy, int $minutes): ?string
    {
        $minimum = (int) ($policy['home_service_min_minutes'] ?? 0);

        if ($minimum <= 0 || $minutes >= $minimum) {
            return null;
        }

        return 'Home service at this spa needs at least ' . BookingPolicy::formatMinutes($minimum) . ' of services, and these take '
            . BookingPolicy::formatMinutes($minutes) . '. Add a service, or book at the spa.';
    }

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
