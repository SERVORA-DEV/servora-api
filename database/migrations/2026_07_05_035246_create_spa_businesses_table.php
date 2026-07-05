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
        Schema::create('spa_businesses', function (Blueprint $table) {
            $table->id();

            // Public Identifier
            $table->uuid('uuid')->unique();

            // Owner
            $table->foreignId('owner_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Business Information
            $table->string('business_name', 150);

            $table->string('business_email')->unique()->nullable();
            $table->string('business_phone', 20)->nullable();

            $table->string('business_logo')->nullable();
            $table->text('business_description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            // Laravel
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spa_businesses');
    }
};
