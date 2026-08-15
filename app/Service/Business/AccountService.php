<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\AccountRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\AccountResource;

class AccountService
{
    private AccountRepository $accountRepository;
    private SpaBranchRepository $spaBranchRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        AccountRepository $accountRepository,
        SpaBranchRepository $spaBranchRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->accountRepository = $accountRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    public function listAccounts(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->accountRepository->paginateForBusiness($business->id, $perPage);
        return AccountResource::collection($collection);
    }

    /**
     * Creates a standalone Manager/Front Officer login — credentials,
     * role, and branch all come from this payload directly (no Staff
     * record involved). Email uniqueness is enforced by AccountRequest.
     */
    public function createAccount(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // spa_branch_uuid must belong to this owner's own business —
        // resolved server-side (404s otherwise) rather than trusted from
        // the payload, same guard StaffService::createStaff uses.
        $branch = $this->spaBranchRepository->findByUuidForBusiness($payload['spa_branch_uuid'], $business->id);

        $account = $this->accountRepository->createUser([
            'role' => $payload['role'],
            'username' => $payload['username'],
            'email' => $payload['email'],
            'password' => $payload['password'],
            'account_status' => 'Active',
        ]);

        $this->accountRepository->assignBranch($account, $branch->id);

        // No granular permission picker on this form — an account gets its
        // role's full permission bundle by default; individual flags can
        // be toggled afterward via updateAccount().
        $roleConfig = config('permission.' . $payload['role'], []);
        $this->accountRepository->createPermission(array_merge(
            array_fill_keys($roleConfig, true),
            ['user_id' => $account->id]
        ));

        // Owner-granted, not self-registered — there's no signup email to
        // confirm, so verify immediately (same as
        // AdminUsersService::createAdminUsers).
        $account->markEmailAsVerified();

        return new AccountResource($account->fresh(['permission', 'accountBranch.branch']));
    }

    public function getAccount(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);
        return new AccountResource($account);
    }

    /**
     * Password reset, suspend/reactivate, branch reassignment, and
     * permission toggles — role is locked at creation (see AccountRequest,
     * which prohibits role on update) since changing it would invalidate
     * the account's permission bundle.
     */
    public function updateAccount(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);

        if (! empty($payload['spa_branch_uuid'])) {
            $branch = $this->spaBranchRepository->findByUuidForBusiness($payload['spa_branch_uuid'], $business->id);
            $this->accountRepository->assignBranch($account, $branch->id);
        }
        unset($payload['spa_branch_uuid']);

        $account = $this->accountRepository->updateAccount($account, $payload);
        return new AccountResource($account);
    }

    // "Deleting an account" is a full revoke now — there's no separate
    // staff record left behind to preserve.
    public function deleteAccount(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);
        $this->accountRepository->revoke($account);
        return true;
    }
}
