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
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('appointment_id')->nullable();

            // Denormalized from subscription->spa_business or appointment->spa_branch->spa_business.
            // Saves a multi-table join on every revenue/report query.
            $table->unsignedBigInteger('spa_business_id')->nullable();
            $table->unsignedBigInteger('spa_branch_id')->nullable();

            $table->enum('billing_type', [
                'Subscription',
                'Appointment',
            ])->nullable();

            $table->string('billing_number', 50)->unique();

            $table->decimal('amount', 10, 2);

            $table->enum('status', [
                'Pending',
                'Paid',
                'Overdue',
                'Cancelled',
                'Refunded',
            ])->default('Pending');

            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('subscription_id');
            $table->index('appointment_id');
            $table->index(['spa_business_id', 'status']);
            $table->index(['spa_branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
