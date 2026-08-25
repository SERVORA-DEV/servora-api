<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Prevents a duplicate (appointment_service, staff) assignment row.
    // Reassigning the same therapist after a cancelled assignment is
    // handled in TherapistAssignmentRepository::assign() via
    // updateOrCreate (reactivates the existing Cancelled row), not by a
    // second insert, so this constraint never blocks that workflow.
    public function up(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->unique(['appointment_service_id', 'staff_id'], 'therapist_assignments_service_staff_unique');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropUnique('therapist_assignments_service_staff_unique');
        });
    }
};
