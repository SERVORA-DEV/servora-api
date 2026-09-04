<?php

namespace App\Repository\Business;

use App\Models\StaffSchedule;

// Only ever touches "current" rows — effective_from/effective_until left
// NULL, per StaffScheduleService's decision to not build a versioning UI yet.
// Those two columns stay reserved for a future scheduled-in-advance feature.
class StaffScheduleRepository
{
    public function currentForStaff(int $staffId)
    {
        return StaffSchedule::where('staff_id', $staffId)
            ->whereNull('effective_from')
            ->whereNull('effective_until')
            ->orderByRaw("FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
            ->get();
    }

    // Whole-set replace inside the caller's transaction — see
    // StaffScheduleService::updateSchedule for why this isn't a per-day
    // upsert (staff_schedules has no unique index, unlike branch_schedules).
    public function replaceCurrentForStaff(int $staffId, array $rows): void
    {
        StaffSchedule::where('staff_id', $staffId)
            ->whereNull('effective_from')
            ->whereNull('effective_until')
            ->delete();

        foreach ($rows as $row) {
            StaffSchedule::create(array_merge($row, ['staff_id' => $staffId]));
        }
    }
}
