<?php

namespace App\Repository;

use App\Models\User;
use App\Models\UserPermission;

class UserRepository
{
    public function findByField(string $field, $value)
    {
        return User::where($field, $value)->first();
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
