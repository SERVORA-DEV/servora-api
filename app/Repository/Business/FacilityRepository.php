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
        return Facility::with('branch')
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Facility::create($payload)->load('branch');
    }

    // Flat (unpaginated) list for the front-office room picker — see
    // FrontOfficeLookupService.
    public function listAvailableForBranches(array $spaBranchIds)
    {
        return Facility::whereIn('spa_branch_id', $spaBranchIds)
            ->where('is_available', true)
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
        return Facility::with('branch')
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
