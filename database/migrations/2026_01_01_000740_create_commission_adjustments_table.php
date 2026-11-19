<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The commission_adjustments table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_adjustments')) {
            return;
        }

        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->bigInteger('appointment_id')->nullable();
            $table->bigInteger('billing_id')->nullable();
            $table->bigInteger('therapist_assignment_id');
            $table->bigInteger('staff_id')->nullable();
            $table->string('mode', 10);
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('original_amount', 10, 2);
            $table->decimal('adjusted_amount', 10, 2);
            $table->string('reason');
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->string('staff_name')->nullable();
            $table->string('service_name')->nullable();
            $table->string('appointment_number', 64)->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique('uuid', 'commission_adjustments_uuid_unique');
            $table->index(['staff_id', 'created_at'], 'commission_adjustments_staff_id_created_at_index');
            $table->index(['therapist_assignment_id', 'id'], 'commission_adjustments_therapist_assignment_id_id_index');
            $table->foreign('appointment_id', 'commission_adjustments_appointment_id_foreign')->references('id')->on('appointments')->onDelete('set null');
            $table->foreign('billing_id', 'commission_adjustments_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('created_by_user_id', 'commission_adjustments_created_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_business_id', 'commission_adjustments_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('staff_id', 'commission_adjustments_staff_id_foreign')->references('id')->on('staff')->onDelete('set null');
            $table->foreign('therapist_assignment_id', 'commission_adjustments_therapist_assignment_id_foreign')->references('id')->on('therapist_assignments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
    }
};
