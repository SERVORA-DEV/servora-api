<?php

namespace App\Repository;

use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RoleRepository
{
    public function paginate(int $perPage = 15)
    {
        return Role::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Role::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Role::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Role::where($field, $value)->firstOrFail();
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
        $model = Role::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}