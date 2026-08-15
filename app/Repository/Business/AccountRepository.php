<?php

namespace App\Repository\Business;

use App\Models\AccountBranch;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Arr;

// An "account" is a standalone Manager/Front Officer login — a User row,
// scoped to a business through account_branches (see AccountBranch), with
// no Staff record involved. See AccountService.
class AccountRepository
{
    // Every account for this business, scoped through account_branches.
    public function paginateForBusiness(int $spaBusinessId, int $perPage = 15)
    {
        return User::with(['permission', 'accountBranch.branch'])
            ->whereIn('role', ['manager', 'front_officer'])
            ->whereHas('accountBranch.branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->latest()
            ->paginate($perPage);
    }

    // Same scope as paginateForBusiness, just a count — used against
    // subscription_plans.max_user_accounts to show staff-seat usage (see
    // SubscriptionService::getCurrentSubscription).
    public function countForBusiness(int $spaBusinessId): int
    {
        return User::whereIn('role', ['manager', 'front_officer'])
            ->whereHas('accountBranch.branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->count();
    }

    // Scoped lookup for show/update/destroy — 404s instead of touching
    // another business's account just because the uuid was guessed/known.
    public function findAccountByUuidForBusiness(string $uuid, int $spaBusinessId): User
    {
        return User::with(['permission', 'accountBranch.branch'])
            ->whereIn('role', ['manager', 'front_officer'])
            ->where('uuid', $uuid)
            ->whereHas('accountBranch.branch', fn ($query) => $query->where('spa_business_id', $spaBusinessId))
            ->firstOrFail();
    }

    public function createUser(array $payload): User
    {
        return User::create($payload);
    }

    public function createPermission(array $payload): UserPermission
    {
        return UserPermission::create($payload);
    }

    // One row per account, enforced by account_branches' unique(user_id) —
    // updateOrCreate so this doubles as both the initial assignment (see
    // AccountService::createAccount) and a later reassignment.
    public function assignBranch(User $account, int $spaBranchId): void
    {
        AccountBranch::updateOrCreate(
            ['user_id' => $account->id],
            ['spa_branch_id' => $spaBranchId]
        );
    }

    // password / account_status / permission — role is never editable here
    // (locked at creation, see AccountRequest); branch goes through
    // assignBranch() instead, not this method.
    public function updateAccount(User $account, array $payload): User
    {
        $userData = Arr::only($payload, ['password', 'account_status']);
        if ($userData) {
            $account->update($userData);
        }

        if (isset($payload['permission'])) {
            $allowedKeys = config('permission.' . $account->role, []);
            $filtered = Arr::only($payload['permission'], $allowedKeys);
            if ($filtered) {
                $account->permission()->update($filtered);
            }
        }

        return $account->fresh(['permission', 'accountBranch.branch']);
    }

    // user_permissions.user_id has cascadeOnDelete — deleting the account
    // is enough to take its permission row with it.
    public function revoke(User $account): void
    {
        $account->delete();
    }
}
