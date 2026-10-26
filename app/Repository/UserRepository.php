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

    // Email is only unique per audience (web = owner-side accounts,
    // mobile = clients), so every email lookup must say which one it wants.
    public function findByEmail(string $email, string $audience)
    {
        return User::with('staff.branch')
            ->where('email', $email)
            ->where('audience', $audience)
            ->first();
    }

    // What someone types to sign in: an email, or (a staff login only) the
    // username the owner's Staff Accounts gave it. Usernames are not case
    // sensitive. An owner, an administrator or a client signs in by email.
    public function findByLogin(string $login, string $audience)
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            return $this->findByEmail($login, $audience);
        }

        return User::with('staff.branch')
            ->whereRaw('lower(username) = ?', [mb_strtolower($login)])
            ->where('audience', $audience)
            ->whereIn('role', ['manager', 'front_officer'])
            ->first();
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
