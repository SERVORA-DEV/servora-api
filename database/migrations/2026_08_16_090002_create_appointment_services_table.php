<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A single service booked within an appointment, with its own line-item
    // pricing/status so multi-service appointments can progress
    // independently (e.g. one service completed while another is still in
    // progress). therapist_assignments hangs off of this, not appointments
    // directly, since staff/facility are assigned per service.
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_id')
                ->constrained('appointments')
                ->cascadeOnDelete();

            // Loose reference, no FK constraint — service_variants
            // (Module 5 - Service Management) was never migrated; branch
            // pricing is currently sourced from branch_services instead.
            $table->unsignedBigInteger('service_variant_id');

            $table->unsignedInteger('quantity')->default(1);

            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('subtotal', 10, 2);

            $table->unsignedInteger('points_earned')->default(0);

            $table->enum('status', [
                'Pending',
                'In Progress',
                'Completed',
                'Cancelled',
            ])->default('Pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};
