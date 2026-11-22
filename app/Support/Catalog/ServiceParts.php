<?php

namespace App\Support\Catalog;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AssignmentStatus;
use App\Enums\StaffStatus;
use App\Models\AppointmentServiceItem;
use App\Models\Service;
use App\Models\ServicePart;
use App\Models\TherapistAssignment;
use Illuminate\Support\Collection;

/**
 * A booked service done by several staff at once (Service::parts): one assignment row per part,
 * each with its own person, and every row in the same room.
 */
final class ServiceParts
{
    /** The parts of a booked line's service, in order; empty for a one-person service. */
    public static function forLine(AppointmentServiceItem $line): Collection
    {
        if (! Service::hasParts()) {
            return collect();
        }
        $serviceId = $line->serviceVariant?->service_id;

        return $serviceId ? ServicePart::where('service_id', $serviceId)->orderBy('sort')->orderBy('id')->get() : collect();
    }

    /** The line's rows that have a person on them and haven't ended. */
    public static function staffedRows(AppointmentServiceItem $line): Collection
    {
        return TherapistAssignment::where('appointment_service_id', $line->id)
            ->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
            ->whereNotNull('staff_id')
            ->get();
    }

    /** The parts nobody is doing yet. */
    public static function missing(AppointmentServiceItem $line, ?Collection $parts = null): Collection
    {
        $parts ??= self::forLine($line);
        if ($parts->isEmpty()) {
            return collect();
        }
        $taken = self::staffedRows($line)->pluck('service_part_id')->filter()->all();

        return $parts->reject(fn (ServicePart $p) => in_array($p->id, $taken, true))->values();
    }

    /**
     * Active therapists at a branch who can do a service (no qualifications set means they can do
     * anything, as StaffServiceRepository::isQualified reads it).
     */
    public static function qualifiedCount(int $branchId, int $serviceId): int
    {
        return \App\Models\Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', StaffStatus::Active->value)
            ->where(fn ($q) => $q
                ->whereNotExists(fn ($s) => $s->from('staff_services')->whereColumn('staff_services.staff_id', 'staff.id'))
                ->orWhereExists(fn ($s) => $s->from('staff_services')->whereColumn('staff_services.staff_id', 'staff.id')->where('staff_services.service_id', $serviceId)))
            ->count();
    }

    /** Why a service done by several staff can't be booked at a branch at all, or null. */
    public static function shortage(int $branchId, \App\Models\ServiceVariant $variant): ?string
    {
        $service = $variant->service;
        $needed = $service?->staffNeeded() ?? 1;
        if ($needed < 2) {
            return null;
        }
        $can = self::qualifiedCount($branchId, $service->id);

        return $can >= $needed
            ? null
            : "{$service->name} needs {$needed} staff working together, and only {$can} at this branch can do it.";
    }

    /**
     * How many staff a booking needs at once: the most any one of its services needs, or more
     * while a package it bought for several clients still waits for the others (SharedPackages):
     * their staff are held from Client A's booking.
     */
    public static function needOf(\App\Models\Appointment $appointment): int
    {
        $line = self::lineNeedOf($appointment);

        // Only facility-based services (a sauna): no therapist, for the client or the others on it.
        return $line === 0 ? 0 : max($line, 1 + \App\Support\Catalog\SharedPackages::openSlots($appointment));
    }

    private static function lineNeedOf(\App\Models\Appointment $appointment): int
    {
        $ids = $appointment->services
            ->filter(fn ($s) => ! in_array($s->status, [AppointmentServiceStatus::Cancelled->value], true))
            ->map(fn ($s) => $s->serviceVariant?->service_id);

        return self::staffFor($ids);
    }

    /** The same, for the options a client is looking at (service_variants.uuid). */
    public static function needOfVariants(array $variantUuids): int
    {
        if (! $variantUuids) {
            return 1;
        }

        return self::staffFor(\App\Models\ServiceVariant::whereIn('uuid', $variantUuids)->pluck('service_id'));
    }

    /**
     * Staff a set of services needs at once: the most any one of them needs, at least one, or none
     * when every one is facility-based (ServiceKind::needsTherapist): nobody is assigned to those.
     * Nothing known yet: one, as before.
     */
    public static function staffFor(iterable $serviceIds): int
    {
        $ids = collect($serviceIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 1;
        }
        $kinds = Service::whereIn('id', $ids)->pluck('service_type', 'id');
        $withStaff = $ids->filter(fn ($id) => ServiceKind::needsTherapist($kinds[$id] ?? null));
        if ($withStaff->isEmpty()) {
            return 0;
        }

        return (int) collect(Service::staffNeededFor($withStaff))->push(1)->max();
    }

    /** The facility-based ones among these services (ids): each is put in a facility of its own kind. */
    public static function roomServices(iterable $serviceIds): array
    {
        $ids = collect($serviceIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Service::whereIn('id', $ids)->get(['id', 'service_type'])
            ->filter(fn ($s) => ! ServiceKind::needsTherapist($s->service_type))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The same for a booking's live lines. */
    public static function roomServicesOf(\App\Models\Appointment $appointment): array
    {
        return self::roomServices($appointment->services
            ->filter(fn ($s) => ! in_array($s->status, [AppointmentServiceStatus::Cancelled->value], true))
            ->map(fn ($s) => $s->serviceVariant?->service_id));
    }

    /** The services of a package (ids). */
    public static function packageServiceIds(\App\Models\Package $package): array
    {
        $package->loadMissing('packageServiceItems');

        return $package->packageServiceItems->map(fn ($i) => $i->theService()?->id)->filter()->values()->all();
    }

    /** Whether a package needs a therapist: any of its services does (a sauna-only one doesn't). */
    public static function packageNeedsStaff(\App\Models\Package $package): bool
    {
        $package->loadMissing('packageServiceItems');
        $services = $package->packageServiceItems->map(fn ($i) => $i->theService())->filter();

        return $services->isEmpty() || $services->contains(fn ($s) => ServiceKind::needsTherapist($s->service_type));
    }

    /** "Upper body", "Upper body and Lower body", "A, B and C". */
    public static function names(Collection $parts): string
    {
        $names = $parts->pluck('name')->values();
        if ($names->count() <= 1) {
            return (string) $names->first();
        }

        return $names->slice(0, -1)->implode(', ') . ' and ' . $names->last();
    }
}
