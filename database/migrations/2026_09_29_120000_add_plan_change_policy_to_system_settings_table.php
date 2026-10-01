<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings > Subscription Policy: whether owners may switch plan, and the
// deadlines before renewal — upgrades close N days before the term ends,
// downgrades need M days' notice. See PlanSwitchService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('plan_changes_enabled')->default(true);
            $table->unsignedInteger('upgrade_cutoff_days')->default(1);
            $table->unsignedInteger('downgrade_notice_days')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['plan_changes_enabled', 'upgrade_cutoff_days', 'downgrade_notice_days']);
        });
    }
};
