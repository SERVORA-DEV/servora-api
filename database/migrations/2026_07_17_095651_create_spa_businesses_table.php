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
            $table->uuid('uuid')->unique();

            $table->foreignId('owner_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('business_name', 150);

            $table->string('business_email')->unique();
            $table->string('business_phone', 20);

            $table->string('business_logo')->nullable();

            $table->text('business_description')->nullable();

            $table->enum('operating_status', [
                'Active',
                'Inactive',
            ])->default('Active');

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