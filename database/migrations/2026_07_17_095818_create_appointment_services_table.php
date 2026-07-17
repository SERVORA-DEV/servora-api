<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('service_variant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity')
                ->default(1);

            $table->decimal('unit_price', 10, 2);

            $table->decimal('discount_amount', 10, 2)
                ->default(0.00);

            $table->decimal('subtotal', 10, 2);

            $table->unsignedInteger('points_earned')
                ->default(0);

            $table->enum('status', [
                'Pending',
                'In Progress',
                'Completed',
                'Cancelled',
            ])->default('Pending');

            $table->timestamps();

            $table->index(['appointment_id']);
            $table->index(['service_variant_id']);
            $table->index(['status']);

            $table->unique([
                'appointment_id',
                'service_variant_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};