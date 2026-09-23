<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When an admin edits a plan that has subscribers, the plan is versioned
// (SubscriptionPlanRepository::archiveAndVersion) and each affected
// subscription is pointed at the new version here, pending the owner's
// answer: accept it for their next renewal, or decline and let the
// subscription end at expires_at (no grace period). See PlanChangeService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('pending_plan_id')->nullable()->after('subscription_plan_id')
                ->constrained('subscription_plans')->nullOnDelete();
            $table->string('plan_change_status', 16)->nullable()->after('pending_plan_id');
            $table->timestamp('plan_change_responded_at')->nullable()->after('plan_change_status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn(['plan_change_status', 'plan_change_responded_at']);
        });
    }
};
