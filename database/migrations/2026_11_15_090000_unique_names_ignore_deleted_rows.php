<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A deleted facility, service or package no longer holds its name or code.
 *
 * These rows are soft-deleted, but the unique indexes counted them too, so an owner who deleted
 * "Massage Room 1" or "Hot Stone Massage" could never add it back ("The name has already been
 * taken"). Each index is rebuilt, under the same name, over live rows only.
 *
 * It also retires what deleting used to leave behind: the price options and branch listings of
 * deleted services, and the branch listings of deleted packages. Deleting now takes them along
 * (ServiceService::deleteService, PackageService::deletePackage); these are the older ones,
 * which showed on the admin's branch page as "Unknown Service".
 */
return new class extends Migration
{
    private const INDEXES = [
        'facilities_spa_branch_id_name_unique' => ['facilities', 'spa_branch_id, name'],
        'services_spa_business_id_name_unique' => ['services', 'spa_business_id, name'],
        'services_spa_business_id_code_unique' => ['services', 'spa_business_id, code'],
        'packages_spa_business_id_name_unique' => ['packages', 'spa_business_id, name'],
        'packages_spa_business_id_code_unique' => ['packages', 'spa_business_id, code'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '8s'");

            // Laravel's ->unique() made these table constraints, which can't carry a WHERE; a
            // partial unique index can. Either form is dropped, so a re-run is safe.
            foreach (self::INDEXES as $name => [$table, $columns]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
                DB::statement("DROP INDEX IF EXISTS {$name}");
                DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns}) WHERE deleted_at IS NULL");
            }

            $now = now();
            DB::table('service_variants')->whereNull('deleted_at')
                ->whereIn('service_id', DB::table('services')->whereNotNull('deleted_at')->select('id'))
                ->update(['deleted_at' => $now]);
            DB::table('branch_services')->whereNull('deleted_at')
                ->whereIn('service_variant_id', DB::table('service_variants')->whereNotNull('deleted_at')->select('id'))
                ->update(['deleted_at' => $now]);
            DB::table('branch_packages')->whereNull('deleted_at')
                ->whereIn('package_id', DB::table('packages')->whereNotNull('deleted_at')->select('id'))
                ->update(['deleted_at' => $now]);
        });
    }

    // Puts the full indexes back. It fails if a deleted row and a live one now share a name,
    // which is exactly what up() allows. The retired leftovers stay retired.
    public function down(): void
    {
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '8s'");

            foreach (self::INDEXES as $name => [$table, $columns]) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE ({$columns})");
            }
        });
    }
};
