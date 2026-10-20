<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Permissions that became enforced after accounts already existed (see
// config('permission.newly_enforced') and the 2026_10_29 migration that
// switches them on for those accounts). Until that migration has run, an
// account's stored flag for such a key is just the column's default (off), not
// a choice the owner made, so it counts as on. Once the migration has run, the
// stored flag is the owner's decision.
final class PermissionGrants
{
    private const MIGRATION = '2026_10_29_090000_grant_newly_enforced_permissions';

    public static function stillPending(string $role, string $key): bool
    {
        if (! in_array($key, config("permission.newly_enforced.{$role}", []), true)) {
            return false;
        }

        return ! self::migrated();
    }

    private static function migrated(): bool
    {
        $known = Cache::get('schema:newly_enforced_permissions');
        if (is_bool($known)) {
            return $known;
        }

        $done = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', self::MIGRATION)->exists();
        $done ? Cache::forever('schema:newly_enforced_permissions', true) : Cache::put('schema:newly_enforced_permissions', false, 60);

        return $done;
    }
}
