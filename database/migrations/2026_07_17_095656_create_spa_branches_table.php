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
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('branch_name', 150);

            $table->string('email')->unique();
            $table->string('phone_number', 20);

            $table->text('address');

            $table->string('city', 100);
            $table->string('province', 100);
            $table->string('postal_code', 10);

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            $table->string('cover_photo')->nullable();
            $table->text('description')->nullable();

            $table->enum('verification_status', [
                'Pending',
                'Verified',
                'Rejected',
                'Suspended',
            ])->default('Pending');

            $table->enum('operating_status', [
                'Active',
                'Inactive',
                'Temporarily Closed',
            ])->default('Active');

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();
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