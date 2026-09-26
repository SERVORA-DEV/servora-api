<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Branch-level management for managers:
//
// - clients.spa_branch_id: the branch where a client record was created. A
//   manager or front officer sees clients created at their branch plus
//   anyone who has booked there (ClientRepository::scopeToBranches). The
//   owner still sees the whole business. Existing rows are backfilled from
//   their first appointment's branch, or the business's only branch.
//
// - Managers now add/edit walk-in clients and run billing & payments at their
//   branch (config/permission.php), so existing manager accounts get those
//   columns switched on — EnsurePermission reads the account's own row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('spa_branch_id')->nullable()->after('spa_business_id')
                ->constrained('spa_branches')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE clients SET spa_branch_id = first_visit.spa_branch_id
            FROM (
                SELECT DISTINCT ON (client_id) client_id, spa_branch_id
                FROM appointments
                ORDER BY client_id, created_at
            ) AS first_visit
            WHERE first_visit.client_id = clients.id AND clients.spa_branch_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE clients SET spa_branch_id = only_branch.id
            FROM (
                SELECT spa_business_id, MIN(id) AS id
                FROM spa_branches
                WHERE deleted_at IS NULL
                GROUP BY spa_business_id
                HAVING COUNT(*) = 1
            ) AS only_branch
            WHERE only_branch.spa_business_id = clients.spa_business_id AND clients.spa_branch_id IS NULL
        SQL);

        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->where('role', 'manager')->select('id'))
            ->update([
                'client_create' => true,
                'client_update' => true,
                'billing_create' => true,
                'payment_create' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spa_branch_id');
        });
    }
};
