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
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('earning_method', [
                'Amount',
                'Service',
                'Visit',
            ])->default('Amount');

            $table->decimal('points_per_currency', 10, 2)
                ->nullable();

            $table->unsignedInteger('points_per_service')
                ->nullable();

            $table->unsignedInteger('points_per_visit')
                ->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('spa_business_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};