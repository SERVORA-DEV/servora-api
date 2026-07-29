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

            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('business_name', 150)->nullable();

            $table->string('business_email')->unique()->nullable();
            $table->string('business_phone', 20)->nullable();

            $table->string('business_logo')->nullable();

            $table->text('business_description')->nullable();

            $table->enum('verification_status', [
                'Pending',
                'Verified',
                'Rejected',
                'Suspended',
            ])->nullable();

            $table->enum('operating_status', [
                'Active',
                'Inactive',
            ])->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

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
