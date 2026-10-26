<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// One branch a staff login works at, and at which level:
//   'operation'   — the front desk only (what the Front Desk may do);
//   'manage_only' — the manager's screens only, no front desk;
//   'management'  — both: the manager's screens, and the front desk too (the
//                   login signs in to Management and switches to Operation,
//                   like the owner). It is the level a Manager login has
//                   always had, so existing accounts keep working as before.
// The owner gives these in Settings → Staff Accounts; see App\Support\AccountScope.
class AccountBranch extends Model
{
    public const MANAGEMENT = 'management';
    public const OPERATION = 'operation';
    public const MANAGEMENT_ONLY = 'manage_only';
    public const LEVELS = [self::MANAGEMENT, self::OPERATION, self::MANAGEMENT_ONLY];

    /** The levels that run the branch (the Manager permissions). */
    public const MANAGER_LEVELS = [self::MANAGEMENT, self::MANAGEMENT_ONLY];

    /** Whether any of these levels runs the branch. */
    public static function runsBranch(string|array|null $levels): bool
    {
        return (bool) array_intersect((array) $levels, self::MANAGER_LEVELS);
    }

    /** Whether the login also has the front desk at a branch at this level. */
    public static function hasFrontDesk(?string $level): bool
    {
        return $level !== self::MANAGEMENT_ONLY;
    }

    protected $table = 'account_branches';

    protected $fillable = ['user_id', 'spa_branch_id', 'level'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    /** Whether logins are given branches yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:account_branches');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('account_branches');
        $ready ? Cache::forever('schema:account_branches', true) : Cache::put('schema:account_branches', false, 60);

        return $ready;
    }

    /**
     * The account's role from its levels: a Manager login when it has
     * Management anywhere, else a Front Officer one. Pure — tested in
     * tests/Unit/AccountScopeTest.
     *
     * @param  string[]  $levels
     */
    public static function roleFor(array $levels): string
    {
        return self::runsBranch($levels) ? 'manager' : 'front_officer';
    }
}
