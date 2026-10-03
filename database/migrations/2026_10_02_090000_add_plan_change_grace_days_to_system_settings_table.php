<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings > Subscription Policy now counts forward from the day an owner
// subscribes: they may upgrade or downgrade for this many days, then the plan
// is locked until its due date. See PlanSwitchService.
//
// Replaces the two "days before renewal" deadlines (upgrade_cutoff_days,
// downgrade_notice_days). Those columns are left in place, unused, so this
// migration doesn't throw away what was configured.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->unsignedInteger('plan_change_grace_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('plan_change_grace_days');
        });
    }
};
