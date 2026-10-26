<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// The owner's refund policies (App\Support\RefundPolicyRule): the reasons a
// paid bill can be refunded for and how much each gives back, picked by the
// front desk when it refunds (Business Defaults → Refunds).
//
//   refund_policies                         the owner's list
//   billing_adjustments.refund_policy_*     the policy a refund was given
//       under, with its own copy of the name and the rule (App\Support\History)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();

            // "Service cut short", "Therapist didn't show"
            $table->string('name', 80);
            // When the front desk should use it.
            $table->string('description', 200)->nullable();
            // full | percent | fixed
            $table->string('mode', 20);
            // percent: the share (1–100); fixed: the amount in pesos.
            $table->decimal('value', 12, 2)->nullable();
            // Staff may refund less than the policy's amount (never more).
            $table->boolean('allow_less')->default(true);
            // Always goes up for approval, even from someone allowed to adjust paid bills.
            $table->boolean('needs_approval')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_business_id', 'deleted_at']);
        });

        Schema::table('billing_adjustments', function (Blueprint $table) {
            $table->foreignId('refund_policy_id')->nullable()->constrained('refund_policies')->nullOnDelete();
            $table->string('refund_policy_name', 80)->nullable();
            $table->string('refund_policy_rule', 120)->nullable();
        });

        Cache::forget('schema:refund_policies');
    }

    public function down(): void
    {
        Schema::table('billing_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_policy_id');
            $table->dropColumn(['refund_policy_name', 'refund_policy_rule']);
        });
        Schema::dropIfExists('refund_policies');

        Cache::forget('schema:refund_policies');
    }
};
