<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('package_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity')
                ->default(1);

            $table->decimal('unit_price', 10, 2);

            $table->decimal('discount_amount', 10, 2)
                ->default(0.00);

            $table->decimal('subtotal', 10, 2);

            $table->enum('status', [
                'Pending',
                'In Progress',
                'Completed',
                'Cancelled',
            ])->default('Pending');

            $table->timestamps();

            $table->unique([
                'appointment_id',
                'package_id',
            ]);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_packages');
    }
};