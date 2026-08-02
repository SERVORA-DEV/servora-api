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
                ->constrained('spa_businesses')
                ->cascadeOnDelete();
            $table->index('spa_business_id');

            // No unique constraint here: branch names are allowed to repeat,
            // even within the same business (e.g. multiple unnamed/placeholder
            // branches during setup, or two branches sharing a franchise name)
            // — see SpaBranchService::createSpaBranch/updateSpaBranch, which
            // doesn't check for an existing name before saving.
            $table->string('branch_name', 150)->nullable();

            $table->string('email')->nullable();
            $table->string('phone_number', 20)->nullable();

            $table->text('address')->nullable();

            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->string('cover_photo')->nullable();

            $table->text('description')->nullable();

            $table->enum('verification_status', [
                'Unregistered',
                'Pending',
                'Verified',
                'Rejected',
                'Suspended',
            ])->default('Unregistered');

            $table->enum('operating_status', [
                'Active',
                'Inactive',
                'Temporarily Closed',
            ])->nullable();

            $table->text('closure_note')->nullable();
            $table->date('reopens_at')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['city', 'province']);
            $table->index(['latitude', 'longitude']);
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
