<?php

namespace App\Repository;

use App\Models\User;

class UserRepository
{
    public function findByField(string $field, $value)
    {
        return User::where($field, $value)->first();
    }
}
