<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// One branch a staff login works at, and at which level: 'management' (what
// a Manager may do) or 'operation' (what the Front Desk may do). The owner
// gives these in Settings → Staff Accounts; see App\Support\AccountScope.
class AccountBranch extends Model
{
    public const MANAGEMENT = 'management';
    public const OPERATION = 'operation';
    public const LEVELS = [self::MANAGEMENT, self::OPERATION];

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
        return in_array(self::MANAGEMENT, $levels, true) ? 'manager' : 'front_officer';
    }
}
