<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Reverses part of 2026_08_03_100000_add_manager_front_officer_accounts_to_users_table.php.
    // Branch assignment now lives on staff.spa_branch_id (staff table
    // created just before this migration) — keeping it here too would give
    // "which branch is this account at" two independent answers that could
    // silently drift apart. A Manager/Front Officer's branch is always
    // reached through user->staff->branch from here on.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['spa_branch_id']);
            $table->dropColumn('spa_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('spa_branch_id')
                ->nullable()
                ->after('role')
                ->constrained('spa_branches')
                ->nullOnDelete();

            $table->index('spa_branch_id');
        });
    }
};
