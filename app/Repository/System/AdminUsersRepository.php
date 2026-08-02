<?php

namespace App\Repository\System;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminUsersRepository
{
    public function paginate(int $perPage = 15)
    {
        return User::with('permission')
        ->latest()
        ->paginate($perPage);
    }

    // Used to fan out branch-registration-submitted notifications to every
    // system administrator, not just one.
    public function allAdministrators()
    {
        return User::where('role', 'system_administrator')->get();
    }

    public function createUser(array $payload)
    {
        return User::create($payload);
    }

    public function createPermission(array $payload)
    {
        return UserPermission::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return User::with('permission')
        ->where('uuid', $uuid)
        ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return User::with('permission')
        ->where($field, $value)
        ->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = User::where('uuid', $uuid)->firstOrFail();

        $userData = Arr::only($payload, [
            'username',
            'first_name',
            'middle_name',
            'last_name',
            'suffix',
            'gender',
            'birth_date',
            'phone_number',
            'email',
            'password',
            'profile_photo',
            'account_status',
        ]);

        $permissionData = Arr::only($payload, config('permission.system_administrator'));

        $model->update($userData);
        $model->permission()->update($permissionData);

        return $model->load('permission');
    }

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