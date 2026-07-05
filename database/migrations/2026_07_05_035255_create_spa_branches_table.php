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
        Schema::create('spa_branches', function (Blueprint $table) {
            $table->id();

            // Public Identifier
            $table->uuid('uuid')->unique();

            // Business
            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Branch Information
            $table->string('branch_name', 150);

            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();

            $table->text('address');
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();

            // Google Maps
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('google_place_id')->nullable();

            // Branch Details
            $table->string('cover_photo')->nullable();
            $table->text('description')->nullable();

            // Verification
            $table->enum('verification_status', [
                'Pending',
                'Verified',
                'Rejected',
                'Suspended'
            ])->default('Pending');

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            // Laravel
            $table->timestamps();
            $table->softDeletes();

            // Unique branch name per business
            $table->unique(['spa_business_id', 'branch_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spa_branches');
    }
};
