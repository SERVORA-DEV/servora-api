<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The therapist_assignments table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('therapist_assignments')) {
            return;
        }

        Schema::create('therapist_assignments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('appointment_service_id');
            $table->bigInteger('staff_id')->nullable();
            $table->bigInteger('facility_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->enum('assignment_status', ['Assigned', 'In Progress', 'Completed', 'Cancelled'])->default('Assigned');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->uuid('uuid');
            $table->string('staff_name')->nullable();
            $table->string('facility_name')->nullable();
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->decimal('commission_rate', 10, 2)->nullable();
            $table->string('commission_type', 12)->nullable();
            $table->bigInteger('service_part_id')->nullable();
            $table->string('part_name', 60)->nullable();
            $table->decimal('commission_share', 5, 2)->nullable();
            $table->unique(['appointment_service_id', 'staff_id'], 'therapist_assignments_service_staff_unique');
            $table->unique('uuid', 'therapist_assignments_uuid_unique');
            $table->index(['appointment_service_id', 'staff_id'], 'therapist_assignments_appointment_service_id_staff_id_index');
            $table->foreign('appointment_service_id', 'therapist_assignments_appointment_service_id_foreign')->references('id')->on('appointment_services')->onDelete('cascade');
            $table->foreign('facility_id', 'therapist_assignments_facility_id_foreign')->references('id')->on('facilities')->onDelete('set null');
            $table->foreign('service_part_id', 'therapist_assignments_service_part_id_foreign')->references('id')->on('service_parts')->onDelete('set null');
            $table->foreign('staff_id', 'therapist_assignments_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_assignments');
    }
};
