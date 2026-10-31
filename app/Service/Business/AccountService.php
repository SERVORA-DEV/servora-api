<?php

namespace App\Service\Business;

use App\Http\Resources\AccountResource;
use App\Models\AccountBranch;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Repository\Business\AccountRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\PlanFitService;
use App\Support\AccountScope;
use App\Support\AppCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Staff logins (Settings → Staff Accounts), the owner's alone.
//
// A login stands on its own: a name, a username and an email, and the
// branches it works at — each at a level, 'management' (what a Manager may
// do) or 'operation' (what the Front Desk may do). Its role follows from the
// levels (a Manager login when it has Management anywhere —
// AccountBranch::roleFor), and so do its starting permissions.
//
// Before the account_branches migration has been run a login is still made
// from an employee record (staff_uuid), as it always was.
class AccountService
{
    private AccountRepository $accountRepository;

    private StaffRepository $staffRepository;

    private SpaBusinessRepository $spaBusinessRepository;

    private BusinessSettingsService $businessSettingsService;

    public function __construct(
        AccountRepository $accountRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
        BusinessSettingsService $businessSettingsService,
        private PlanFitService $planFit,
    ) {
        $this->accountRepository = $accountRepository;
        $this->staffRepository = $staffRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->businessSettingsService = $businessSettingsService;
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

    public function createAccount(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);
        if (! $business) {
            return response()->json(['message' => 'No spa business found.'], 422);
        }

        return DB::transaction(function () use ($user, $payload, $business) {
            SpaBusiness::whereKey($business->id)->lockForUpdate()->first();

            return $this->createAccountWithinCapacity($user, $payload);
        });
    }

    private function createAccountWithinCapacity(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        if (! AccountBranch::ready()) {
            return $this->createForStaff($user, $business, $payload);
        }

        $branches = $this->branchesFrom($business, $payload['branches'] ?? []);
        if ($branches instanceof JsonResponse) {
            return $branches;
        }

        // No new login only at branches the plan has put on hold, and the
        // plan's staff-login limit (logins on hold don't count).
        if ($branches->every(fn ($b) => $b['branch']->plan_hold_at)) {
            return response()->json(['message' => 'Every branch picked is on hold, so no login can be added there.', 'code' => 'PLAN_HOLD'], 422);
        }
        if ($limit = $this->planLimitProblem($business)) {
            return $limit;
        }

        $role = AccountBranch::roleFor($branches->pluck('level')->all());

        $account = DB::transaction(function () use ($payload, $role, $branches, $business) {
            $account = $this->accountRepository->createUser([
                'role' => $role,
                'first_name' => trim((string) ($payload['first_name'] ?? '')),
                'last_name' => trim((string) ($payload['last_name'] ?? '')),
                'username' => $payload['username'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'account_status' => 'Active',
            ]);
            $this->accountRepository->syncBranches($account, $branches->map(fn ($b) => ['spa_branch_id' => $b['branch']->id, 'level' => $b['level']])->all());

            // An account starts with the owner's defaults for its role
            // (Business Settings → Staff Policies); the owner can change single
            // permissions afterwards.
            $defaults = $this->businessSettingsService->settingsFor($business)->rolePermissions($role);
            $this->accountRepository->createPermission(array_merge($defaults, ['user_id' => $account->id]));

            return $account;
        });

        // Owner-granted, not self-registered — there's no signup email to
        // confirm, so verify immediately (same as AdminUsersService::createAdminUsers).
        $account->markEmailAsVerified();

        return new AccountResource($this->accountRepository->fresh($account));
    }

    /**
     * Suggests a free username/email pair, in one of two owner-chosen shapes:
     *
     *   role_branch -> manager.mandug      (the level's role + the first branch's code)
     *   name        -> juan.delacruz       (the account's own name)
     *
     * Generated server-side because users.username and users.email are unique
     * across every user in the system, which the owner's own list can't see.
     * The password is generated in the browser, never here.
     */
    public function suggestCredentials(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        if (! empty($payload['staff_uuid'])) {
            // Before logins were given branches: made from an employee.
            $staff = $this->staffRepository->findByUuidForBusiness($payload['staff_uuid'], $business->id);
            $first = $staff->first_name;
            $last = $staff->last_name;
            $branch = $staff->branch;
            $role = Staff::ACCOUNT_ROLE_MAP[$staff->role] ?? 'front_officer';
        } else {
            $branch = ! empty($payload['branch_uuid'])
                ? SpaBranch::where('spa_business_id', $business->id)->where('uuid', $payload['branch_uuid'])->first()
                : null;
            $first = $payload['first_name'] ?? '';
            $last = $payload['last_name'] ?? '';
            $role = AccountBranch::runsBranch($payload['level'] ?? AccountBranch::OPERATION) ? 'manager' : 'front_officer';
        }

        $base = $payload['format'] === 'name'
            ? $this->join($first, $last)
            : $this->join(str_replace('_', '', $role), $branch?->code ?: $branch?->branch_name);

        if ($base === '') {
            return response()->json([
                'message' => $payload['format'] === 'name'
                    ? 'Type the name first, or use the other format.'
                    : 'Pick a branch first, or use the other format.',
            ], 422);
        }

        return response()->json([
            'data' => $this->accountRepository->nextAvailableIdentity($base, $this->emailDomain($business, $branch, $user)),
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
     * Name, branches and levels, password, suspend/reactivate, and single
     * permissions. A change of branches signs the login out everywhere (like
     * a password change), and a change of role brings in the defaults for
     * the permissions it gains.
     */
    public function updateAccount(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);

        // Suspended by the plan: it comes back through bringBack() (which
        // checks there is room), not by setting the status by hand.
        if ($account->plan_hold_at && ($payload['account_status'] ?? null) === 'Active') {
            return response()->json([
                'message' => 'This login is on hold because your plan has no room for it. Upgrade, or bring it back once there is room.',
                'code' => 'PLAN_HOLD',
            ], 422);
        }

        if (AccountBranch::ready() && array_key_exists('branches', $payload)) {
            $branches = $this->branchesFrom($business, $payload['branches'] ?? []);
            if ($branches instanceof JsonResponse) {
                return $branches;
            }
            $rows = $branches->map(fn ($b) => ['spa_branch_id' => $b['branch']->id, 'level' => $b['level']])->all();
            $before = $account->accountBranches()->orderBy('spa_branch_id')->get(['spa_branch_id', 'level'])->map(fn ($r) => "{$r->spa_branch_id}:{$r->level}")->all();
            $after = collect($rows)->sortBy('spa_branch_id')->map(fn ($r) => "{$r['spa_branch_id']}:{$r['level']}")->values()->all();

            if ($before !== $after) {
                DB::transaction(function () use ($account, $rows, $business) {
                    $this->accountRepository->syncBranches($account, $rows);
                    $role = AccountBranch::roleFor(array_column($rows, 'level'));
                    if ($role !== $account->role) {
                        // The permissions the new role brings start at the owner's defaults.
                        $gained = array_diff(config('permission.'.$role, []), config('permission.'.$account->role, []));
                        $defaults = array_intersect_key($this->businessSettingsService->settingsFor($business)->rolePermissions($role), array_flip($gained));
                        $account->update(['role' => $role]);
                        if ($defaults && $account->permission) {
                            $account->permission()->update($defaults);
                        }
                        AppCache::forget("user:{$account->id}:perm");
                    }
                    // Where it may work changed: sign it out everywhere.
                    $account->tokens()->delete();
                });
                AccountScope::forget($account);
            }
        }
        unset($payload['branches']);

        $account = $this->accountRepository->updateAccount($account, $payload);

        return new AccountResource($account);
    }

    // POST business/account/{uuid}/bring-back — let a login the plan
    // suspended sign in again, when the plan has room for it.
    public function bringBack(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $account = $this->accountRepository->findAccountByUuidForBusiness($uuid, $business->id);
        if ($why = $this->planFit->bringBackAccount($business, $account)) {
            return response()->json(['message' => $why, 'code' => 'PLAN_LIMIT'], 422);
        }

        return new AccountResource($this->accountRepository->fresh($account));
    }

    // "Deleting an account" is a full revoke — the employees it may have
    // been made from stay on the staff list.
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

    // ── internals ───────────────────────────────────────────────────────

    /**
     * The branches sent, checked: at least one, all this business's own, no
     * branch twice, each at a known level.
     *
     * @return Collection<int, array{branch: SpaBranch, level: string}>|JsonResponse
     */
    private function branchesFrom(SpaBusiness $business, array $sent)
    {
        $rows = collect($sent)->map(fn ($r) => ['uuid' => (string) ($r['branch_uuid'] ?? ''), 'level' => (string) ($r['level'] ?? '')]);
        if ($rows->isEmpty()) {
            return $this->refuse('Give the login at least one branch.', 'branches');
        }
        if ($rows->pluck('uuid')->duplicates()->isNotEmpty()) {
            return $this->refuse('A branch is listed twice.', 'branches');
        }
        if ($rows->contains(fn ($r) => ! in_array($r['level'], AccountBranch::LEVELS, true))) {
            return $this->refuse('Pick Management or Operation for each branch.', 'branches');
        }

        $found = SpaBranch::where('spa_business_id', $business->id)->whereIn('uuid', $rows->pluck('uuid'))->get()->keyBy('uuid');
        if ($found->count() !== $rows->count()) {
            return $this->refuse('One of the branches was not found.', 'branches');
        }

        return $rows->map(fn ($r) => ['branch' => $found[$r['uuid']], 'level' => $r['level']])->values();
    }

    private function planLimitProblem(SpaBusiness $business): ?JsonResponse
    {
        $plan = $this->planFit->currentPlan($business);
        if ($this->planFit->hasRoomForAccount($business, $plan)) {
            return null;
        }

        return response()->json([
            'message' => "Your {$plan->name} plan allows ".((int) $plan->max_user_accounts === 1 ? '1 staff login' : "{$plan->max_user_accounts} staff logins").'. Upgrade to add another.',
            'code' => 'PLAN_LIMIT',
        ], 422);
    }

    // Before the account_branches migration: a login made from an employee,
    // its role and branch read from them (as it always was).
    private function createForStaff(User $user, SpaBusiness $business, array $payload)
    {
        if (empty($payload['staff_uuid'])) {
            return $this->refuse('Pick the staff member this login is for.', 'staff_uuid');
        }
        $staff = $this->staffRepository->findByUuidForBusiness($payload['staff_uuid'], $business->id);

        if ($staff->user_id !== null) {
            return response()->json(['message' => 'This staff member already has an account.'], 422);
        }
        if (! array_key_exists($staff->role, Staff::ACCOUNT_ROLE_MAP)) {
            return response()->json(['message' => 'Only Manager or Front Desk staff can be granted a login.'], 422);
        }
        if ($staff->branch?->plan_hold_at) {
            return response()->json(['message' => "{$staff->branch->branch_name} is on hold, so no logins can be added there.", 'code' => 'PLAN_HOLD'], 422);
        }
        if ($limit = $this->planLimitProblem($business)) {
            return $limit;
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
        $defaults = $this->businessSettingsService->settingsFor($business)->rolePermissions($role);
        $this->accountRepository->createPermission(array_merge($defaults, ['user_id' => $account->id]));
        $account->markEmailAsVerified();

        return new AccountResource($this->accountRepository->fresh($account));
    }

    // Dot-joins two slugged parts, skipping either if it slugs away to
    // nothing so the result never starts, ends, or doubles up on a dot.
    // ("Dela Cruz" slugs to one part, "delacruz".)
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

    // Generated emails borrow a domain the business already uses: the
    // business's email, the branch's, then the owner's login email. The
    // suggestion lands in an editable field, and nothing is mailed to it.
    private function emailDomain(SpaBusiness $business, ?SpaBranch $branch, User $owner): string
    {
        foreach ([$business->business_email, $branch?->email, $owner->email] as $candidate) {
            if ($candidate && str_contains($candidate, '@')) {
                return Str::after($candidate, '@');
            }
        }

        return 'example.com';
    }

    private function refuse(string $message, string $field): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
