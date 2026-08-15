<?php

namespace App\Repository\Business;

use App\Models\Service;

class ServiceRepository
{
    // Services are a business-wide catalog (not branch-scoped) — both owner
    // and manager see the same full list, per config/permission.php granting
    // manager service_view/service_update (unlike branches, which stay
    // owner-only for anything beyond index/show).
    //
    // branchIds scopes the eager-loaded branchServices only, not which
    // services are returned — every service is still visible to both roles,
    // but a manager's response only carries their own branch's
    // availability/price row, never a sibling branch's.
    public function paginateForBusiness(int $spaBusinessId, array $branchIds, int $perPage = 15)
    {
        return Service::with(['branchServices' => fn ($q) => $q->whereIn('spa_branch_id', $branchIds)->with('branch')])
            ->where('spa_business_id', $spaBusinessId)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Service::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Service::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // (or letting someone edit) another business's service just because its
    // uuid was guessed/known. branchIds is optional and only controls
    // whether/how branchServices is eager-loaded (see paginateForBusiness) —
    // callers that don't need branch data (plain update/delete) omit it.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId, ?array $branchIds = null)
    {
        $query = Service::where('uuid', $uuid)->where('spa_business_id', $spaBusinessId);

        if ($branchIds !== null) {
            $query->with(['branchServices' => fn ($q) => $q->whereIn('spa_branch_id', $branchIds)->with('branch')]);
        }

        return $query->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }
}
