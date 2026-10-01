<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An owner's downgrade (or billing-cycle switch): they keep the current plan
// until expires_at, and their next payment is for this plan. See
// PlanSwitchService. Separate from pending_plan_id, which is an admin's edit
// of the plan itself (PlanChangeService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_id')->nullable()->after('plan_change_responded_at')
                ->constrained('subscription_plans')->nullOnDelete();
            $table->string('scheduled_billing_cycle', 16)->nullable()->after('scheduled_plan_id');
            $table->timestamp('scheduled_at')->nullable()->after('scheduled_billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn(['scheduled_billing_cycle', 'scheduled_at']);
        });
    }
};
