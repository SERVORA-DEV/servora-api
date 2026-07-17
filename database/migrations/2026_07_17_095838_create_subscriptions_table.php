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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subscription_plan_id')
                ->constrained()
                ->restrictOnDelete();

            $table->enum('billing_cycle', [
                'Monthly',
                'Yearly',
            ]);

            $table->decimal('plan_price', 10, 2);

            $table->dateTime('starts_at');
            $table->dateTime('expires_at');

            $table->boolean('auto_renew')
                ->default(false);

            $table->enum('status', [
                'Pending',
                'Active',
                'Expired',
                'Cancelled',
            ])->default('Pending');

            $table->timestamps();
            $table->softDeletes();

            $table->index('spa_business_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};