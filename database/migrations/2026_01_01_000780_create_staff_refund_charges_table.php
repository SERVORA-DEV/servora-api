<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_refund_charges table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_refund_charges')) {
            return;
        }

        Schema::create('staff_refund_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('spa_branch_id')->nullable();
            $table->bigInteger('billing_adjustment_id');
            $table->bigInteger('billing_id')->nullable();
            $table->bigInteger('staff_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('charged_on');
            $table->string('staff_name')->nullable();
            $table->string('billing_number', 64)->nullable();
            $table->string('policy_name', 80)->nullable();
            $table->timestamps();
            $table->unique('uuid', 'staff_refund_charges_uuid_unique');
            $table->index(['spa_business_id', 'charged_on'], 'staff_refund_charges_spa_business_id_charged_on_index');
            $table->index(['staff_id', 'charged_on'], 'staff_refund_charges_staff_id_charged_on_index');
            $table->foreign('billing_adjustment_id', 'staff_refund_charges_billing_adjustment_id_foreign')->references('id')->on('billing_adjustments')->onDelete('cascade');
            $table->foreign('billing_id', 'staff_refund_charges_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('spa_branch_id', 'staff_refund_charges_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
            $table->foreign('spa_business_id', 'staff_refund_charges_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('staff_id', 'staff_refund_charges_staff_id_foreign')->references('id')->on('staff')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_refund_charges');
    }
};
