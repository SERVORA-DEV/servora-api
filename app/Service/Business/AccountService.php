<?php

namespace App\Service\Business;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\AccountRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\AccountResource;
use Illuminate\Support\Str;

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

    /**
     * Suggests a free username/email pair for a login about to be granted to
     * the staff member in $payload, in one of two owner-chosen shapes:
     *
     *   role_branch -> manager.mandug      (login role + the branch's code)
     *   name        -> juan.delacruz       (the staff member's own name)
     *
     * Generated server-side rather than in the browser because users.username
     * and users.email are unique across every user in the system - clients and
     * other businesses included - so the owner's own account list can't tell
     * whether a candidate is free. Opens with the same guards createAccount()
     * uses, so this can never preview an account that couldn't actually be
     * created.
     *
     * The password is deliberately NOT generated here: it has to reach the
     * owner in plaintext (see AccountCreatedModal on the web app), and the
     * browser already holds the only copy, so generating it there keeps it out
     * of this response entirely.
     */
    public function suggestCredentials(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBusiness($payload['staff_uuid'], $business->id);

        if (! array_key_exists($staff->role, Staff::ACCOUNT_ROLE_MAP)) {
            return response()->json(['message' => 'Only Manager or Front Desk staff can be granted a login.'], 422);
        }

        $base = $payload['format'] === 'name'
            ? $this->nameBase($staff)
            : $this->roleBranchBase($staff);

        // Everything the chosen format reads from could be blank or
        // punctuation-only (a branch with neither a code nor a usable name, a
        // staff member whose names don't survive slugging). Say so rather than
        // handing back a bare "@domain.com".
        if ($base === '') {
            return response()->json([
                'message' => 'Not enough information to generate a username for this staff member - try the other format, or enter one manually.',
            ], 422);
        }

        return response()->json([
            'data' => $this->accountRepository->nextAvailableIdentity(
                $base,
                $this->emailDomain($business, $staff->branch, $user)
            ),
        ]);
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

    // "manager.mandug" - the login role this staff member's job role maps to
    // (front_officer collapses to "frontofficer"), joined to the branch's
    // owner-typed code. spa_branches.code is optional, so fall back to the
    // branch name: a branch with no code still has to generate something.
    private function roleBranchBase(Staff $staff): string
    {
        $role = str_replace('_', '', Staff::ACCOUNT_ROLE_MAP[$staff->role]);
        $branch = $staff->branch?->code ?: $staff->branch?->branch_name;

        return $this->join($role, $branch);
    }

    // "juan.delacruz" - spaces inside a part are dropped rather than
    // separated, so "Dela Cruz" reads as the one surname it is.
    private function nameBase(Staff $staff): string
    {
        return $this->join($staff->first_name, $staff->last_name);
    }

    // Dot-joins two slugged parts, skipping either if it slugs away to
    // nothing so the result never starts, ends, or doubles up on a dot.
    private function join(?string $left, ?string $right): string
    {
        $parts = array_filter([$this->slug($left), $this->slug($right)], fn ($part) => $part !== '');

        return implode('.', $parts);
    }

    // Stricter than Str::slug on purpose: this value becomes an email local
    // part as well as a username, so hyphens and anything non-ascii are
    // stripped rather than kept or transliterated into a separator.
    private function slug(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $value)));
    }

    // Generated emails borrow a domain the business already uses.
    // spa_businesses.business_email is nullable, so fall back to the branch's
    // own contact email, then to the owner's login email (users.email is NOT
    // NULL, so this always resolves). That last fallback can be a personal
    // mailbox domain, which wouldn't be a real inbox for the new account -
    // acceptable because the suggestion lands in an editable field and the
    // owner sees it before submitting, and because nothing is mailed to it
    // (createAccount calls markEmailAsVerified immediately).
    private function emailDomain(SpaBusiness $business, ?SpaBranch $branch, User $owner): string
    {
        foreach ([$business->business_email, $branch?->email, $owner->email] as $candidate) {
            if ($candidate && str_contains($candidate, '@')) {
                return Str::after($candidate, '@');
            }
        }

        return 'example.com';
    }
}
