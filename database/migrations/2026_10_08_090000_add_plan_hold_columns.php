<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Moving to a smaller plan (App\Service\PlanFitService): the branches and
// staff logins that don't fit the plan are put on hold rather than deleted.
//   spa_branches.plan_hold_at / users.plan_hold_at — when the plan put it on
//     hold (null = not on hold). The hold itself reuses what already exists:
//     a branch is switched off (operating_status Inactive), a login is
//     suspended (account_status Suspended); this column is what tells a
//     plan hold apart from the owner's own choice, and what a later upgrade
//     releases.
//   subscriptions.scheduled_keep — the branches and logins the owner chose
//     to keep when they scheduled a downgrade.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_branches', function (Blueprint $table) {
            $table->timestamp('plan_hold_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('plan_hold_at')->nullable();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('scheduled_keep')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spa_branches', fn (Blueprint $table) => $table->dropColumn('plan_hold_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('plan_hold_at'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('scheduled_keep'));
    }
};
