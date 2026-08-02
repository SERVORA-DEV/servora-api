<?php

namespace App\Repository\Business;

use App\Models\SpaBranch;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SpaBranchRepository
{
    public function paginate(int $perPage = 15)
    {
        return SpaBranch::latest()->paginate($perPage);
    }

    // Scoped listing for the owner's own "Branches" page — paginate() above
    // has no business filter, so every owner would see every business's
    // branches.
    public function paginateForBusiness(int $spaBusinessId, int $perPage = 15)
    {
        return SpaBranch::where('spa_business_id', $spaBusinessId)->latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return SpaBranch::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return SpaBranch::where('uuid', $uuid)->firstOrFail();
    }

    // Scoped lookup used by show/update/destroy — 404s instead of returning
    // another business's branch just because its uuid was guessed/known.
    public function findByUuidForBusiness(string $uuid, int $spaBusinessId)
    {
        return SpaBranch::where('uuid', $uuid)
            ->where('spa_business_id', $spaBusinessId)
            ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return SpaBranch::where($field, $value)->firstOrFail();
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

    public function restore(string $uuid)
    {
        $model = SpaBranch::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}