<?php

namespace App\Service\Business;

use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\AccountRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\AccountResource;

class AccountService
{
    private AccountRepository $accountRepository;
    private StaffRepository $staffRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(
        AccountRepository $accountRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
    ) {
        $this->accountRepository = $accountRepository;
        $this->staffRepository = $staffRepository;
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
     * Creates a standalone Manager/Front Officer login tied to a specific
     * staff member — credentials come from this payload, but role and
     * branch are both derived from that staff member (see
     * Staff::ACCOUNT_ROLE_MAP), never trusted from the client. Email/
     * username uniqueness is enforced by AccountRequest.
     */
    public function createAccount(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // staff_uuid must belong to this owner's own business — resolved
        // server-side (404s otherwise) rather than trusted from the
        // payload, same guard StaffService::createStaff uses for branch.
        $staff = $this->staffRepository->findByUuidForBusiness($payload['staff_uuid'], $business->id);

        if ($staff->user_id !== null) {
            return response()->json(['message' => 'This staff member already has an account.'], 422);
        }

        if (! array_key_exists($staff->role, Staff::ACCOUNT_ROLE_MAP)) {
            return response()->json(['message' => 'Only Manager or Front Desk staff can be granted a login.'], 422);
        }

        $role = Staff::ACCOUNT_ROLE_MAP[$staff->role];

        $account = $this->accountRepository->createUser([
            'role' => $role,
            'username' => $payload['username'],
            'email' => $payload['email'],
            'password' => $payload['password'],
            'account_status' => 'Active',
        ]);

        $this->accountRepository->assignStaff($account, $staff);

        // No granular permission picker on this form — an account gets its
        // role's full permission bundle by default; individual flags can
        // be toggled afterward via updateAccount().
        $roleConfig = config('permission.' . $role, []);
        $this->accountRepository->createPermission(array_merge(
            array_fill_keys($roleConfig, true),
            ['user_id' => $account->id]
        ));

        // Owner-granted, not self-registered — there's no signup email to
        // confirm, so verify immediately (same as
        // AdminUsersService::createAdminUsers).
        $account->markEmailAsVerified();

        return new AccountResource($account->fresh(['permission', 'staff.branch']));
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
     * Password reset, suspend/reactivate, and permission toggles — role and
     * staff assignment are both locked at creation (see AccountRequest,
     * which prohibits both on update) since changing either would
     * invalidate the account's permission bundle / detach it from the
     * employee it represents.
     */
    public function updateAccount(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);

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
