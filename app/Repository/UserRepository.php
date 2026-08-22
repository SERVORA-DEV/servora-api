<?php

namespace App\Repository;

use App\Models\User;
use App\Models\UserPermission;

class UserRepository
{
    // Eager-loads staff.branch so UserResource can surface the linked
    // employee record (manager/front_officer accounts only — see
    // User::staff()) without an extra query per request.
    public function findByField(string $field, $value)
    {
        return User::with('staff.branch')->where($field, $value)->first();
    }

    public function create(array $payload)
    {
        return User::create($payload);
    }

    public function update(User $user, array $payload)
    {
        $user->update($payload);
        return $user;
    }

    public function createPermission(array $payload)
    {
        return UserPermission::create($payload);
    }
}
