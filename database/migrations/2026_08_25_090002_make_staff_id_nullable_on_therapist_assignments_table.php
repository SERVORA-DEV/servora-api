<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lets a room be reserved for a service before a therapist is picked
    // (staff_id IS NULL = "room reserved, unclaimed"; see
    // TherapistAssignmentRepository::assignRoomOnly/claim).
    //
    // Safe under the existing unique(appointment_service_id, staff_id)
    // index (2026_08_22_090006...): Postgres treats NULLs as distinct under
    // a unique index by default, so multiple unclaimed (staff_id NULL)
    // rows for different services never collide, and
    // TherapistAssignmentRepository::assignRoomOnly()'s updateOrCreate
    // keyed on staff_id => null reuses the one row per service rather than
    // inserting a second.
    public function up(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
        });

        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_id')->nullable()->change();
        });

        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
        });

        // No NULLs are expected to exist before reverting this migration —
        // room-only assignments are a feature introduced alongside it, and
        // rolling back should only happen before that feature ships data.
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_id')->nullable(false)->change();
        });

        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });
    }
};
