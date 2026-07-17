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
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('service_variant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('reward_type', [
                'Service',
                'Percentage Discount',
                'Fixed Discount',
            ])->default('Service');

            $table->string('name', 150);

            $table->text('description')->nullable();

            $table->unsignedInteger('points_required');

            $table->decimal('discount_value', 10, 2)
                ->nullable();

            $table->unsignedInteger('quantity_available')
                ->nullable();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'spa_business_id',
                'name',
            ]);

            $table->index('reward_type');
            $table->index('service_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};