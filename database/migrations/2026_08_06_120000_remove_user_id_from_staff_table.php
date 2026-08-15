<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // staff.user_id was the original "grant this staff row a login" link
    // (see 2026_08_03_110000_create_staff_table.php). Account creation was
    // later redesigned to be fully independent — a standalone User row
    // scoped to a branch via account_branches (see AccountBranch,
    // AccountService::createAccount) — so no current code path ever writes
    // a non-null value here. Dropping it removes the last dead plumbing
    // from the old design (Staff::user(), User::staff(),
    // AccountRepository::resyncAccountRole(), and the has_account/
    // account_uuid fields it fed on the API/frontend).
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('spa_branch_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }
};
