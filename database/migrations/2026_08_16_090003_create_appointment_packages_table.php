<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A package booked within an appointment — same line-item shape as
    // appointment_services, but package_services already fans a package out
    // into its component services, so there's no per-package therapist
    // assignment here.
    public function up(): void
    {
        Schema::create('appointment_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_id')
                ->constrained('appointments')
                ->cascadeOnDelete();

            $table->foreignId('package_id')
                ->constrained('packages')
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('subtotal', 10, 2);

            $table->enum('status', [
                'Pending',
                'In Progress',
                'Completed',
                'Cancelled',
            ])->default('Pending');

            $table->timestamps();

            $table->index(['appointment_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_packages');
    }
};
