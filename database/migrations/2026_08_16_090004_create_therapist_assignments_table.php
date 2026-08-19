<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which staff member (and which room) is handling a booked service.
    // facility_id is nullable since a therapist is typically assigned
    // before a room is picked (assigned_at vs. started_at) — see facilities
    // migration, whose status is set manually rather than derived from a
    // booking.
    public function up(): void
    {
        Schema::create('therapist_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_service_id')
                ->constrained('appointment_services')
                ->cascadeOnDelete();

            $table->foreignId('staff_id')
                ->constrained('staff')
                ->cascadeOnDelete();

            $table->foreignId('facility_id')
                ->nullable()
                ->constrained('facilities')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->enum('assignment_status', [
                'Assigned',
                'In Progress',
                'Completed',
                'Cancelled',
            ])->default('Assigned');

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['appointment_service_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_assignments');
    }
};
