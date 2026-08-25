<?php

namespace App\Repository\Business;

use App\Models\StaffQualification;

class StaffServiceRepository
{
    // Opt-in-if-configured: a staff member with zero rows here is treated
    // as qualified for every service, so a fresh install doesn't require
    // every therapist to be configured before any assignment works. Once at
    // least one row exists for a staff member, it becomes a whitelist.
    public function isQualified(int $staffId, int $serviceId): bool
    {
        $hasAnyConfigured = StaffQualification::where('staff_id', $staffId)->exists();

        if (! $hasAnyConfigured) {
            return true;
        }

        return StaffQualification::where('staff_id', $staffId)->where('service_id', $serviceId)->exists();
    }

    // Replaces this staff member's qualifications wholesale — edited as a
    // full set (checkbox list) from the form, never one row at a time, same
    // pattern as PackageService::syncPackageServices.
    public function sync(int $staffId, array $serviceIds): void
    {
        StaffQualification::where('staff_id', $staffId)->delete();

        foreach (array_unique($serviceIds) as $serviceId) {
            StaffQualification::create([
                'staff_id' => $staffId,
                'service_id' => $serviceId,
            ]);
        }
    }
}
