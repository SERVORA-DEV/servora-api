<?php

namespace App\Repository\Admin;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminUsersRepository
{
    // public function paginate(int $perPage = 15)
    // {
    //     return AdminUsers::latest()->paginate($perPage);
    // }

    // public function create(array $payload)
    // {
    //     return AdminUsers::create($payload);
    // }

    // public function findByUuid(string $uuid)
    // {
    //     return AdminUsers::where('uuid', $uuid)->firstOrFail();
    // }

    // public function findByField(string $field, $value)
    // {
    //     return AdminUsers::where($field, $value)->firstOrFail();
    // }

    // public function update(string $uuid, array $payload)
    // {
    //     $model = $this->findByUuid($uuid);
    //     $model->update($payload);
    //     return $model;
    // }

    // public function delete(string $uuid)
    // {
    //     $model = $this->findByUuid($uuid);
    //     return $model->delete();
    // }

    // public function restore(string $uuid)
    // {
    //     $model = AdminUsers::withTrashed()->where('uuid', $uuid)->firstOrFail();
    //     $model->restore();
    //     return $model;
    // }
}