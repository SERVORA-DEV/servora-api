<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A catalog entry clients can redeem points for — a free/discounted
    // service (service_variant_id) or a percentage/fixed discount, per
    // reward_type. discount_value and service_variant_id are nullable since
    // only one applies depending on reward_type; quantity_available null
    // means unlimited.
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnDelete();

            // Loose reference, no FK constraint — same convention as
            // services.created_by.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->enum('reward_type', ['Service', 'Percentage Discount', 'Fixed Discount'])->default('Service');

            // Loose reference, no FK constraint — service_variants (Module 5
            // - Service Management) was never migrated; see the same note on
            // appointment_services.service_variant_id.
            $table->unsignedBigInteger('service_variant_id')->nullable();

            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->unsignedInteger('points_required');

            $table->decimal('discount_value', 10, 2)->nullable();

            $table->unsignedInteger('quantity_available')->nullable();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
