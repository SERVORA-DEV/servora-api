<?php

namespace App\Repository\Business;

use App\Models\AccountBranch;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Arr;

// An "account" is a standalone Manager/Front Officer login — a User row,
// scoped to a business through the branches it was given (account_branches,
// see App\Support\AccountScope). Before that migration has been run, through
// the staff member it was made from (staff.user_id), as it used to be.
class AccountRepository
{
    // A business's staff logins, by whichever link exists.
    private function ofBusiness($query, int $spaBusinessId)
    {
        return AccountBranch::ready()
            ? $query->whereHas('accountBranches.branch', fn ($q) => $q->where('spa_business_id', $spaBusinessId))
            : $query->whereHas('staff.branch', fn ($q) => $q->where('spa_business_id', $spaBusinessId));
    }

    private function relations(): array
    {
        return AccountBranch::ready() ? ['permission', 'accountBranches.branch'] : ['permission', 'staff.branch'];
    }

    // Every account for this business.
    public function paginateForBusiness(int $spaBusinessId, int $perPage = 15)
    {
        return $this->ofBusiness(User::with($this->relations())->whereIn('role', ['manager', 'front_officer']), $spaBusinessId)
            ->latest()
            ->paginate($perPage);
    }

    // Same scope as paginateForBusiness, just a count — used against
    // subscription_plans.max_user_accounts to show staff-seat usage (see
    // SubscriptionService::getCurrentSubscription).
    public function countForBusiness(int $spaBusinessId): int
    {
        return $this->ofBusiness(User::whereIn('role', ['manager', 'front_officer']), $spaBusinessId)->count();
    }

    // Scoped lookup for show/update/destroy — 404s instead of touching
    // another business's account just because the uuid was guessed/known.
    public function findAccountByUuidForBusiness(string $uuid, int $spaBusinessId): User
    {
        return $this->ofBusiness(User::with($this->relations())->whereIn('role', ['manager', 'front_officer'])->where('uuid', $uuid), $spaBusinessId)
            ->firstOrFail();
    }

    public function fresh(User $account): User
    {
        return $account->fresh($this->relations());
    }

    // Finds the first free username/email pair for a generated base, bumping
    // a numeric suffix on collision (manager.mandug -> manager.mandug2 -> ...).
    // Both advance together so the two halves of an account's identity never
    // drift apart.
    //
    // A plain query is the right scope here: users carries a deleted_at column
    // but the User model has no SoftDeletes trait, so revoke() hard-deletes and
    // every surviving row is visible to both this probe and AccountRequest's
    // Rule::unique. The two have to agree, or a suggestion would 422 the moment
    // it's submitted.
    //
    // Capped rather than looping forever: 100 collisions on one base means
    // something is wrong upstream, and handing back a taken value lets
    // AccountRequest produce the real error instead of hanging the request.
    public function nextAvailableIdentity(string $base, string $domain): array
    {
        $identity = [];

        for ($suffix = 1; $suffix <= 100; $suffix++) {
            $username = $suffix === 1 ? $base : $base . $suffix;
            $identity = ['username' => $username, 'email' => $username . '@' . $domain];

            $taken = User::query()
                ->where(fn ($query) => $query
                    ->where('username', $identity['username'])
                    ->orWhere('email', $identity['email']))
                ->exists();

            if (! $taken) {
                break;
            }
        }

        return $identity;
    }

    public function createUser(array $payload): User
    {
        return User::create($payload);
    }

    public function createPermission(array $payload): UserPermission
    {
        return UserPermission::create($payload);
    }

    /** Replaces the login's branches with these rows ({spa_branch_id, level}). */
    public function syncBranches(User $account, array $rows): void
    {
        AccountBranch::where('user_id', $account->id)->whereNotIn('spa_branch_id', array_column($rows, 'spa_branch_id'))->delete();
        foreach ($rows as $row) {
            AccountBranch::updateOrCreate(['user_id' => $account->id, 'spa_branch_id' => $row['spa_branch_id']], ['level' => $row['level']]);
        }
        $account->unsetRelation('accountBranches');
    }

    // Before logins were given branches: links the new account to the staff
    // member it was created for (staff.user_id is unique, so a staff member
    // backs one account at most).
    public function assignStaff(User $account, Staff $staff): void
    {
        $staff->update(['user_id' => $account->id]);
    }

    // name / password / account_status / permission. Branches and levels
    // (and so the role) go through AccountService::updateAccount.
    public function updateAccount(User $account, array $payload): User
    {
        $userData = Arr::only($payload, ['password', 'account_status', 'first_name', 'last_name']);
        if ($userData) {
            $account->update($userData);
        }

        // Suspending a login, or the owner setting a new password, has to end
        // the sessions already open — otherwise a suspended staff member who
        // is signed in keeps working until their token happens to expire.
        $blocked = in_array($userData['account_status'] ?? null, ['Suspended', 'Inactive'], true);
        if ($blocked || isset($userData['password'])) {
            $account->tokens()->delete();
        }

        if (isset($payload['permission'])) {
            $allowedKeys = config('permission.' . $account->role, []);
            $filtered = Arr::only($payload['permission'], $allowedKeys);
            // No column to hold it until the migration is run.
            if (! \App\Service\Business\PolicyGate::ready()) {
                unset($filtered['policy_override']);
            }
            if ($filtered) {
                $account->permission()->update($filtered);
                // A query update fires no model event — drop the cached row.
                \App\Support\AppCache::forget("user:{$account->id}:perm");
            }
        }

        return $this->fresh($account);
    }

    // user_permissions.user_id has cascadeOnDelete — deleting the account
    // is enough to take its permission row with it (and its branches).
    public function revoke(User $account): void
    {
        $account->delete();
    }
}
