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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->enum('category', [
                'Basic',
                'Premium',
                'Enterprise',
            ]);

            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('yearly_price', 10, 2)->nullable();

            $table->enum('billing_cycle', [
                'Monthly',
                'Yearly',
                'Both',
            ]);

            // Plan Limits
            $table->integer('max_branches');
            $table->integer('max_user_accounts');

            // Premium Features
            $table->boolean('package_access')->default(false);

            $table->boolean('reward_access')->default(false);
            $table->boolean('review_access')->default(false);

            $table->boolean('report_access')->default(false);
            $table->boolean('report_export')->default(false);

            $table->boolean('mobile_app_access')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};