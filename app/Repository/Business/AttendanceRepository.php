<?php

namespace App\Repository\Business;

use App\Models\Attendance;
use App\Models\Staff;

class AttendanceRepository
{
    // Active staff in these branches, each with (at most, thanks to the
    // attendances_staff_date_unique constraint) their one attendance row for
    // this date eager-loaded — a staff member with no row yet is left with
    // an empty `attendance` collection, which the Service reads as
    // "unmarked" rather than an explicit status.
    public function rosterForDate(array $spaBranchIds, string $date)
    {
        return Staff::with([
            'branch',
            'attendance' => fn ($query) => $query->where('attendance_date', $date),
        ])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();
    }

    // All attendance rows in range for these branches, staff+branch eager
    // loaded — the Owner analytics report aggregates trend/breakdown/top-
    // issues client-side from this flat list rather than duplicating that
    // logic in SQL, same approach useOwnerAppointmentsPage.ts uses.
    public function rangeForBranches(array $spaBranchIds, string $dateFrom, string $dateTo)
    {
        return Attendance::with('staff.branch')
            ->whereHas('staff', fn ($query) => $query->whereIn('spa_branch_id', $spaBranchIds))
            ->whereBetween('attendance_date', [$dateFrom, $dateTo])
            ->orderByDesc('attendance_date')
            ->get();
    }

    // Scoped lookup used by clearAttendance — 404s instead of clearing (or
    // even revealing the existence of) an attendance row belonging to a
    // staff member outside this user's own branches, same guard
    // StaffRepository::findByUuidForBranches uses for staff.
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return Attendance::with('staff.branch')
            ->where('uuid', $uuid)
            ->whereHas('staff', fn ($query) => $query->whereIn('spa_branch_id', $spaBranchIds))
            ->firstOrFail();
    }

    // Same scoped lookup, but eager-loading everything the detail view and
    // AttendanceStatusCalculator need in one query — the staff member's
    // full schedule history (calculator matches the right row internally)
    // plus who created/last touched this record.
    public function findByUuidForBranchesWithDetail(string $uuid, array $spaBranchIds)
    {
        return Attendance::with(['staff.branch', 'staff.schedules', 'creator.staff', 'updater.staff'])
            ->where('uuid', $uuid)
            ->whereHas('staff', fn ($query) => $query->whereIn('spa_branch_id', $spaBranchIds))
            ->firstOrFail();
    }

    // Active staff in these branches for the whole range, each with their
    // full schedule history and only their in-range attendance rows eager
    // loaded — the Service cross-produces staff x each date in the range
    // and runs AttendanceStatusCalculator per pair, so a staff member with
    // zero rows on a given date still produces a synthetic
    // Absent/Incomplete/unmarked entry instead of being silently omitted.
    public function rosterForRange(array $spaBranchIds, string $dateFrom, string $dateTo)
    {
        return Staff::with([
            'branch',
            'schedules',
            'attendance' => fn ($query) => $query->whereBetween('attendance_date', [$dateFrom, $dateTo]),
        ])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();
    }

    // One row per (staff, date) — attendances_staff_date_unique — so marking
    // a day twice always updates the existing row rather than duplicating it.
    public function upsert(int $staffId, string $date, array $payload)
    {
        $model = Attendance::updateOrCreate(
            ['staff_id' => $staffId, 'attendance_date' => $date],
            $payload
        );

        return $model->load('staff.branch');
    }

    public function findByUuid(string $uuid)
    {
        return Attendance::where('uuid', $uuid)->firstOrFail();
    }

    // Correction path — a targeted update on an already-existing row (only
    // reachable once a record exists at all, since AttendanceCorrectionRequest
    // is validated against an existing uuid), distinct from upsert()'s
    // create-or-overwrite-by-(staff,date) semantics used by the daily
    // quick-mark flow.
    public function updateByUuid(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->fill($payload);
        $model->save();

        return $model->load('staff.branch');
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }
}
