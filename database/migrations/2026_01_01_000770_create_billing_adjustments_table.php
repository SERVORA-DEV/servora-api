<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The billing_adjustments table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_adjustments')) {
            return;
        }

        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('spa_branch_id')->nullable();
            $table->bigInteger('billing_id');
            $table->string('kind', 20);
            $table->decimal('amount', 12, 2)->default(0);
            $table->bigInteger('client_voucher_id')->nullable();
            $table->string('voucher_name', 120)->nullable();
            $table->string('reason');
            $table->string('status', 20)->default('pending');
            $table->string('needs', 20)->nullable();
            $table->bigInteger('requested_by_user_id')->nullable();
            $table->string('requested_by_name', 160)->nullable();
            $table->string('requested_by_role', 30)->nullable();
            $table->bigInteger('decided_by_user_id')->nullable();
            $table->string('decided_by_name', 160)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->bigInteger('refund_policy_id')->nullable();
            $table->string('refund_policy_name', 80)->nullable();
            $table->string('refund_policy_rule', 120)->nullable();
            $table->string('charge_to', 20)->nullable();
            $table->decimal('staff_share', 5, 2)->nullable();
            $table->string('charge_rule', 120)->nullable();
            $table->decimal('staff_charged', 12, 2)->default(0);
            $table->bigInteger('appointment_service_id')->nullable();
            $table->string('service_name')->nullable();
            $table->unique('uuid', 'billing_adjustments_uuid_unique');
            $table->index(['billing_id', 'status'], 'billing_adjustments_billing_id_status_index');
            $table->index(['spa_business_id', 'status'], 'billing_adjustments_spa_business_id_status_index');
            $table->foreign('appointment_service_id', 'billing_adjustments_appointment_service_id_foreign')->references('id')->on('appointment_services')->onDelete('set null');
            $table->foreign('billing_id', 'billing_adjustments_billing_id_foreign')->references('id')->on('billings')->onDelete('cascade');
            $table->foreign('client_voucher_id', 'billing_adjustments_client_voucher_id_foreign')->references('id')->on('client_vouchers')->onDelete('set null');
            $table->foreign('decided_by_user_id', 'billing_adjustments_decided_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('refund_policy_id', 'billing_adjustments_refund_policy_id_foreign')->references('id')->on('refund_policies')->onDelete('set null');
            $table->foreign('requested_by_user_id', 'billing_adjustments_requested_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_branch_id', 'billing_adjustments_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'billing_adjustments_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_adjustments');
    }
};
