<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A standing "this client requests/favors this therapist" preference,
    // distinct from the one-off requested_therapist_uuid a front-desk user
    // types in at booking time (AppointmentRequest/AppointmentService) —
    // that field is never persisted; this column is what remembers the
    // choice across visits. Single therapist only, not a favorites list —
    // nullOnDelete so removing the staff member just clears the preference
    // rather than blocking the deletion.
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('preferred_staff_id')
                ->nullable()
                ->after('user_id')
                ->constrained('staff')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_staff_id');
        });
    }
};
