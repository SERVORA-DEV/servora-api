<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Who a refund comes out of (App\Support\RefundCharge): the business, the
// staff who did the visit, or both — set per refund policy.
//
//   refund_policies.charge_to / staff_share     the owner's choice
//   billing_adjustments.staff_charged / charge_rule
//       what of a refund came off staff earnings, and the rule as it was
//   staff_refund_charges
//       each staff member's part, dated the day it was refunded, read by the
//       Earnings pages like a penalty. Keeps its own copy of who and what
//       (App\Support\History).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            // business | staff | both
            $table->string('charge_to', 20)->default('business');
            // both: the staff's share in percent (1–99).
            $table->decimal('staff_share', 5, 2)->nullable();
        });

        Schema::table('billing_adjustments', function (Blueprint $table) {
            // Who it comes out of, as the policy said when it was asked for.
            $table->string('charge_to', 20)->nullable();
            $table->decimal('staff_share', 5, 2)->nullable();
            $table->string('charge_rule', 120)->nullable();
            // What of it came off staff earnings once applied.
            $table->decimal('staff_charged', 12, 2)->default(0);
        });

        Schema::create('staff_refund_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('billing_adjustment_id')->constrained('billing_adjustments')->cascadeOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            // The day it comes off their earnings (the day it was refunded).
            $table->date('charged_on');

            $table->string('staff_name', 255)->nullable();
            $table->string('billing_number', 64)->nullable();
            $table->string('policy_name', 80)->nullable();

            $table->timestamps();

            $table->index(['staff_id', 'charged_on']);
            $table->index(['spa_business_id', 'charged_on']);
        });

        Cache::forget('schema:refund_charges');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_refund_charges');
        Schema::table('billing_adjustments', fn (Blueprint $table) => $table->dropColumn(['charge_to', 'staff_share', 'charge_rule', 'staff_charged']));
        Schema::table('refund_policies', fn (Blueprint $table) => $table->dropColumn(['charge_to', 'staff_share']));

        Cache::forget('schema:refund_charges');
    }
};
