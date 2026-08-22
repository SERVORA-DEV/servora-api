<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Accounts (Manager/Front Officer logins) move from being assigned to a
    // branch (account_branches, see 2026_08_04_110000) to being assigned to
    // an employee (staff.user_id) — a branch is now only ever reached
    // transitively via the employee's own spa_branch_id. This restores
    // exactly the column 2026_08_06_120000 removed, and drops the pivot
    // table it was replaced with.
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('spa_branch_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::dropIfExists('account_branches');
    }

    public function down(): void
    {
        Schema::create('account_branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('spa_branch_id')
                ->constrained('spa_branches')
                ->cascadeOnDelete();

            $table->timestamps();
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
