<?php

namespace App\Repository\Business;

use App\Models\Package;

class PackageRepository
{
    // branchIds scopes the eager-loaded branchPackages only, not which
    // packages are returned — see ServiceRepository::paginateForBusiness for
    // the identical reasoning (avoids leaking a sibling branch's
    // availability/price to a manager).
    public function paginateForBusiness(int $spaBusinessId, array $branchIds, int $perPage = 15)
    {
        return Package::with([
            'packageServiceItems.serviceVariant.service',
            'branchPackages' => fn ($q) => $q->whereIn('spa_branch_id', $branchIds)->with('branch'),
        ])
            ->where('spa_business_id', $spaBusinessId)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Package::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Package::where('uuid', $uuid)->firstOrFail();
    }

    // branchIds is optional and only controls whether/how branchPackages is
    // eager-loaded — see ServiceRepository::findByUuidForBusiness.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId, ?array $branchIds = null)
    {
        $query = Package::with('packageServiceItems.serviceVariant.service')
            ->where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId);

        if ($branchIds !== null) {
            $query->with(['branchPackages' => fn ($q) => $q->whereIn('spa_branch_id', $branchIds)->with('branch')]);
        }

        return $query->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model->load('packageServiceItems.serviceVariant.service');
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }
}
