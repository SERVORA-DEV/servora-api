<?php

namespace App\Support;

// Whether a business fits inside a plan's limits, and whether the owner's
// choice of what to keep is a valid one. Pure rules over plain arrays — the
// reading and writing is App\Service\PlanFitService — so they can be tested
// without a database.
//
//   $branches  [['id' => 1, 'name' => 'Main', 'upcoming' => 3], …]
//              `upcoming`: appointments still booked on or after the day the
//              plan changes. A branch can't be put on hold over them.
//   $accounts  [['id' => 10, 'branch_ids' => [1, 2], 'name' => 'Ana'], …]
//              staff logins (manager / front desk) and the branches each
//              works at (a lone `branch_id` is read as one branch).
//
// Only what is NOT already on hold is passed in.
final class PlanFit
{
    // API convention for "no limit" on max_branches / max_user_accounts.
    public const UNLIMITED = 9999;

    public static function needsChoice(int $maxBranches, int $maxAccounts, array $branches, array $accounts): bool
    {
        return self::over($maxBranches, count($branches)) || self::over($maxAccounts, count($accounts));
    }

    /**
     * Everything wrong with the choice, as sentences for the owner. Empty
     * means it can go ahead.
     *
     * @param  int[]  $keepBranchIds
     * @param  int[]  $keepAccountIds
     * @return string[]
     */
    public static function problems(
        int $maxBranches,
        int $maxAccounts,
        array $branches,
        array $accounts,
        array $keepBranchIds,
        array $keepAccountIds,
    ): array {
        $branchIds = array_column($branches, 'id');
        $keepBranchIds = array_values(array_unique($keepBranchIds));
        $keepAccountIds = array_values(array_unique($keepAccountIds));
        $problems = [];

        if (array_diff($keepBranchIds, $branchIds)) {
            return ['One of the branches chosen isn\'t one of yours, or is already on hold.'];
        }
        if ($branches && ! $keepBranchIds) {
            $problems[] = 'Choose ' . ($maxBranches === 1 ? 'the branch' : 'the branches') . ' to keep.';
        }
        if (self::over($maxBranches, count($keepBranchIds))) {
            $problems[] = 'This plan allows ' . self::count($maxBranches, 'branch', 'branches') . '; ' . count($keepBranchIds) . ' are chosen.';
        }

        $byId = array_column($accounts, null, 'id');
        if (array_diff($keepAccountIds, array_keys($byId))) {
            return ['One of the staff logins chosen isn\'t one of yours, or is already on hold.'];
        }
        foreach ($keepAccountIds as $id) {
            // Kept while at least one branch they work at is.
            $theirs = $byId[$id]['branch_ids'] ?? [$byId[$id]['branch_id'] ?? null];
            if (! array_intersect($theirs, $keepBranchIds)) {
                $problems[] = "{$byId[$id]['name']} works only at " . (count($theirs) > 1 ? 'branches that are' : 'a branch that is') . " going on hold, so their login can't be kept.";
            }
        }
        if (self::over($maxAccounts, count($keepAccountIds))) {
            $problems[] = 'This plan allows ' . self::count($maxAccounts, 'staff login', 'staff logins') . '; ' . count($keepAccountIds) . ' are chosen.';
        }

        // Nothing is cancelled for the owner: they clear these first.
        foreach ($branches as $branch) {
            if (! in_array($branch['id'], $keepBranchIds, true) && ($branch['upcoming'] ?? 0) > 0) {
                $problems[] = "{$branch['name']} still has " . self::count((int) $branch['upcoming'], 'appointment', 'appointments')
                    . ' booked on or after the day the plan changes. Finish, move or cancel '
                    . ((int) $branch['upcoming'] === 1 ? 'it' : 'them') . ' first.';
            }
        }

        return $problems;
    }

    /**
     * What goes on hold once a valid choice is applied.
     *
     * @return array{hold_branches: int[], hold_accounts: int[]}
     */
    public static function outcome(array $branches, array $accounts, array $keepBranchIds, array $keepAccountIds): array
    {
        return [
            'hold_branches' => array_values(array_diff(array_column($branches, 'id'), $keepBranchIds)),
            'hold_accounts' => array_values(array_diff(array_column($accounts, 'id'), $keepAccountIds)),
        ];
    }

    /** Whether $count more can be added under $limit. */
    public static function hasRoom(int $limit, int $inUse, int $adding = 1): bool
    {
        return $limit >= self::UNLIMITED || $inUse + $adding <= $limit;
    }

    private static function over(int $limit, int $count): bool
    {
        return $limit < self::UNLIMITED && $count > $limit;
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }
}
