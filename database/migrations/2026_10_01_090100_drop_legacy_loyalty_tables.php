<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The original "Module 7" loyalty schema (loyalty_settings, rewards,
// reward_redemptions + appointments.reward_redemption_id) was never wired to
// any model, route or screen, and is replaced by customer_programs and the
// client_* tables. Dropped so there's one loyalty model, not two.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['reward_redemption_id']);
            $table->dropColumn('reward_redemption_id');
        });

        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('loyalty_settings');
    }

    public function down(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->unique()->constrained('spa_businesses')->cascadeOnDelete();
            $table->enum('earning_method', ['Amount', 'Service', 'Visit'])->default('Amount');
            $table->decimal('points_per_currency', 8, 2)->nullable();
            $table->unsignedInteger('points_per_visit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->enum('reward_type', ['Service', 'Percentage Discount', 'Fixed Discount']);
            $table->unsignedBigInteger('service_variant_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('points_required');
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->unsignedInteger('quantity_available')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('rewards')->cascadeOnDelete();
            $table->unsignedInteger('points_used');
            $table->enum('status', ['Pending', 'Redeemed', 'Cancelled'])->default('Pending');
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedBigInteger('redeemed_by')->nullable();
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('reward_redemption_id')->nullable()->constrained('reward_redemptions')->nullOnDelete();
        });
    }
};
