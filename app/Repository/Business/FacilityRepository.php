<?php

namespace App\Repository\Business;

use App\Models\Facility;

class FacilityRepository
{
    // Scoped to a specific set of branch ids — the same
    // SpaBusinessRepository::branchesForUser scoping every other
    // branch-aware listing (staff, branch) uses: every branch's rooms for
    // business_owner, only the manager's own branch's rooms for manager.
    public function paginateForBranches(array $spaBranchIds, int $perPage = 15)
    {
        return Facility::with(['branch', 'services'])
            ->withExists(['therapistAssignments as is_occupied' => fn ($q) => $q->where('assignment_status', 'In Progress')])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Facility::create($payload)->load('branch');
    }

    // Flat (unpaginated) list for the front-office room picker — see
    // FrontOfficeLookupService. Excludes rooms under maintenance; a room
    // still marked Available but currently occupied is filtered by the
    // caller's own time-window check, not here. A room with no services
    // configured at all is treated as unrestricted (matches the same
    // backward-compatible rule enforced in AppointmentService's
    // checkRoomEligibility) rather than being hidden from every filtered
    // picker just because it hasn't been categorized yet.
    public function listAvailableForBranches(array $spaBranchIds, ?int $serviceId = null)
    {
        return Facility::whereIn('spa_branch_id', $spaBranchIds)
            ->where('is_available', true)
            ->where('status', '!=', 'Maintenance')
            ->when($serviceId, fn ($q) => $q->where(fn ($q2) => $q2
                ->doesntHave('services')
                ->orWhereHas('services', fn ($sq) => $sq->where('services.id', $serviceId))
            ))
            ->orderBy('name')
            ->get();
    }

    public function findByUuid(string $uuid)
    {
        return Facility::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // (or letting a manager edit) a room outside their own branch, same
    // guard StaffRepository::findByUuidForBranches uses for staff.
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return Facility::with(['branch', 'services'])
            ->withExists(['therapistAssignments as is_occupied' => fn ($q) => $q->where('assignment_status', 'In Progress')])
            ->where('uuid', $uuid)
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model->load('branch');
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }
}
