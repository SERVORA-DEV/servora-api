<?php

namespace App\Support;

use App\Models\AccountBranch;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;

// Where a staff login (Manager / Front Officer) may work, and at which level.
//
// The owner gives a login its branches in Settings → Staff Accounts, each at
// a level (AccountBranch): 'management' — what a Manager may do (staff,
// schedules, services, settings, reports) — or 'operation' — what the Front
// Desk may do (queue, bookings, cashiering). A Management branch also allows
// everything Operation does.
//
// Management-only routes (those a front officer can't reach: role groups
// without front_officer) only ever see the login's Management branches —
// RoleMiddleware marks the request, and SpaBusinessRepository::
// branchIdsForUser reads that mark here. So an account that is Management at
// one branch and Operation at another can run the first and only work the
// front desk at the second.
//
// Before the migration has been run, a login works where its employee
// record is (staff.user_id), as it always did.
class AccountScope
{
    // Set on the request by RoleMiddleware for Management-only routes.
    public const LEVEL_ATTRIBUTE = 'account_level';

    /**
     * The login's branches; when $level is given (or the route asks for
     * Management), only those at that level. levelAt() says which is which.
     *
     * @return Collection<int, SpaBranch>
     */
    //
    // $narrow: when the web says which branch it is working in (X-Branch),
    // only that one — so every screen of a login with several branches works
    // on the branch picked in its switcher, and on all of them when it sends
    // none ("All my branches").
    public static function branches(User $user, ?string $level = null, bool $narrow = true): Collection
    {
        $level ??= self::requiredLevel();
        $branches = self::all($user);
        if ($level === AccountBranch::MANAGEMENT) {
            $levels = self::levels($user);
            $branches = $branches->filter(fn (SpaBranch $b) => AccountBranch::runsBranch($levels[$b->id] ?? null))->values();
        }

        $picked = $narrow ? request()?->header('X-Branch') : null;
        if ($picked) {
            $only = $branches->where('uuid', $picked)->values();
            if ($only->isNotEmpty()) {
                return $only;
            }
        }

        return $branches;
    }

    /** @return int[] */
    public static function branchIds(User $user, ?string $level = null): array
    {
        return self::branches($user, $level)->pluck('id')->all();
    }

    /** The level this login has at a branch, or null when it isn't one of theirs. */
    public static function levelAt(User $user, int $branchId): ?string
    {
        return self::levels($user)[$branchId] ?? null;
    }

    /** @return array<int, string> level per branch id */
    public static function levels(User $user): array
    {
        self::all($user);

        return $user->getRelation('scopeLevels')->all();
    }

    public static function business(User $user): ?SpaBusiness
    {
        return self::all($user)->first()?->business;
    }

    /** Every branch of the login, whatever the route or the switcher says (its own screens list them). */
    public static function allBranches(User $user): Collection
    {
        return self::all($user);
    }

    /**
     * The branch a one-branch screen works on (the booking board, the front
     * desk's dashboard): the one the web sent as X-Branch when it is one of
     * the allowed ones, else the first of them.
     *
     * @param  int[]  $allowedIds
     */
    public static function workingBranchId(array $allowedIds, ?string $requestedUuid = null): ?int
    {
        $requestedUuid ??= request()?->header('X-Branch');
        if ($requestedUuid) {
            $id = SpaBranch::where('uuid', $requestedUuid)->value('id');
            if ($id && in_array($id, $allowedIds, true)) {
                return $id;
            }
        }

        return $allowedIds[0] ?? null;
    }

    /** The branch a one-branch action happens at (see workingBranchId), on this route's level. */
    public static function workingBranch(User $user): ?SpaBranch
    {
        $branches = self::branches($user);
        $id = self::workingBranchId($branches->pluck('id')->all());

        return $id ? $branches->firstWhere('id', $id) : null;
    }

    /**
     * The logins to tell about something at these branches. 'manager' means
     * Management there; 'frontdesk' means Operation there (a Management
     * login at a branch is told what its front desk is told, as a manager
     * always was when asked).
     *
     * @param  int[]  $branchIds
     * @param  string[]  $roles  'manager' and/or 'frontdesk'
     * @return int[] user ids
     */
    public static function userIdsAt(array $branchIds, array $roles): array
    {
        if (! $branchIds || ! $roles) {
            return [];
        }

        if (! AccountBranch::ready()) {
            return Staff::whereIn('spa_branch_id', $branchIds)
                ->where('status', 'active')
                ->whereIn('role', $roles)
                ->whereNotNull('user_id')
                ->pluck('user_id')->unique()->values()->all();
        }

        $levels = [];
        if (in_array('manager', $roles, true)) {
            array_push($levels, ...AccountBranch::MANAGER_LEVELS);
        }
        if (in_array('frontdesk', $roles, true)) {
            $levels[] = AccountBranch::OPERATION;
        }

        return AccountBranch::whereIn('spa_branch_id', $branchIds)
            ->whereIn('level', $levels)
            ->whereHas('user', fn ($q) => $q->where('account_status', 'Active'))
            ->pluck('user_id')->unique()->values()->all();
    }

    /** The level the current request needs (Management-only routes), or null. */
    public static function requiredLevel(): ?string
    {
        return request()?->attributes->get(self::LEVEL_ATTRIBUTE);
    }

    /** Forget what was read for this login (after its branches change). */
    public static function forget(User $user): void
    {
        $user->unsetRelation('scopeBranches');
        $user->unsetRelation('scopeLevels');
    }

    // Read once per request and kept on the user model.
    private static function all(User $user): Collection
    {
        if ($user->relationLoaded('scopeBranches')) {
            return $user->getRelation('scopeBranches');
        }

        // The levels are kept beside the branches, not on them, so a branch
        // read here can still be saved by its caller.
        if (AccountBranch::ready()) {
            $levels = AccountBranch::where('user_id', $user->id)->pluck('level', 'spa_branch_id');
            $branches = $levels->isEmpty()
                ? collect()
                : SpaBranch::with('business')->whereIn('id', $levels->keys())->orderBy('branch_name')->get();
        } else {
            // Before the migration: the employee record's one branch, at its role's level.
            $staff = $user->staff;
            $branch = $staff?->branch;
            $branches = $branch ? collect([$branch]) : collect();
            $levels = collect($branch ? [$branch->id => $staff->role === 'manager' ? AccountBranch::MANAGEMENT : AccountBranch::OPERATION] : []);
        }

        $user->setRelation('scopeBranches', $branches);
        $user->setRelation('scopeLevels', $levels);

        return $branches;
    }
}
