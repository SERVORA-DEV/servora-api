<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which services a staff member is qualified to perform — tracked at
    // the Service level (not per-variant): qualified for "Swedish Massage"
    // covers every duration variant of it. A staff member with zero rows
    // here is treated as qualified for everything (opt-in-if-configured —
    // see AppointmentAvailabilityService::isStaffQualified) so rollout
    // doesn't require configuring every therapist upfront.
    public function up(): void
    {
        Schema::create('staff_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_id')
                ->constrained('staff')
                ->cascadeOnDelete();

            $table->foreignId('service_id')
                ->constrained('services')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['staff_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_services');
    }
};
