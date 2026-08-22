<?php

namespace App\Repository\Business;

use App\Models\Staff;

class StaffRepository
{
    // Scoped listing for the owner's own staff roster — paginate() has no
    // business filter, so every owner would see every business's staff.
    public function paginateForBusiness(int $spaBusinessId, int $perPage = 15)
    {
        return Staff::with(['branch', 'user'])
            ->whereHas('branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->latest()
            ->paginate($perPage);
    }

    // Scoped to a specific set of branch ids — what listStaff() actually
    // uses now (via SpaBusinessRepository::branchesForUser), since a manager
    // must only ever see their own branch's roster, not the whole business's
    // like paginateForBusiness() above returns.
    public function paginateForBranches(array $spaBranchIds, int $perPage = 15)
    {
        return Staff::with(['branch', 'user'])
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Staff::create($payload);
    }

    // Best-effort sequential employee number (EMP-0001, EMP-0002, ...) scoped
    // to the branch, since the unique index is (spa_branch_id, employee_number)
    // rather than global. withTrashed so a deleted staff member's number is
    // never handed out again. Collection-based rather than SQL SUBSTRING so
    // this isn't tied to a specific DB driver — branch rosters are small
    // enough that this isn't a perf concern.
    public function nextEmployeeNumber(int $spaBranchId): string
    {
        $highest = Staff::withTrashed()
            ->where('spa_branch_id', $spaBranchId)
            ->where('employee_number', 'like', 'EMP-%')
            ->pluck('employee_number')
            ->map(fn (string $number) => (int) substr($number, 4))
            ->max();

        return 'EMP-' . str_pad((string) (($highest ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function findByUuid(string $uuid)
    {
        return Staff::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // (or letting the owner edit) another business's staff member just
    // because their uuid was guessed/known.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId)
    {
        return Staff::with(['branch', 'user'])
            ->where('uuid', $uuid)
            ->whereHas('branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->firstOrFail();
    }

    // Same guard as findByUuidForBusiness, scoped to specific branch ids —
    // what show/update/destroy actually use now, so a manager 404s (rather
    // than being able to view/edit/delete) a staff member outside their own
    // branch, same as a business_owner already 404s outside their business.
    public function findByUuidForBranches(string $uuid, array $spaBranchIds)
    {
        return Staff::with(['branch', 'user'])
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

    public function restore(string $uuid)
    {
        $model = Staff::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}
