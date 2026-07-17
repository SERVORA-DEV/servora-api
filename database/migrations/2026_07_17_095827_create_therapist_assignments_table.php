<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_service_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('staff_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('facility_id')
                ->nullable()
                ->constrained()
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

            $table->unique('appointment_service_id'); // if only one therapist per service
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_assignments');
    }
};