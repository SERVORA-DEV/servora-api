<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which branch a Manager/Front Officer account operates at — kept out
    // of the users table itself (unlike the short-lived
    // 2026_08_04_100000 migration this replaces) so the account/branch
    // link lives in its own table instead of widening users with a
    // business-account-specific column. unique(user_id) caps it at one
    // branch per account, same behavior as before.
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('account_branches');
    }
};
