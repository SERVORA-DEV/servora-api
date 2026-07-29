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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_id')
                ->constrained('billings');

            // Denormalized from billings for one-hop reporting (avoids payments -> billings join on every query)
            $table->unsignedBigInteger('spa_business_id')->nullable();
            $table->unsignedBigInteger('spa_branch_id')->nullable();

            $table->enum('payment_method', [
                'Cash',
                'GCash',
                'Maya',
                'Bank Transfer',
                'Credit Card',
                'Debit Card',
            ])->nullable();

            // Set when the payment was processed through an online gateway (e.g. Xendit); null for cash/manual entries
            $table->string('gateway_provider', 50)->nullable();
            $table->string('gateway_reference', 150)->nullable();

            $table->string('reference_number', 100)->nullable();

            $table->decimal('amount', 10, 2);

            $table->enum('payment_status', [
                'Pending',
                'Paid',
                'Failed',
                'Refunded',
            ])->default('Pending');

            $table->timestamp('paid_at')->nullable();

            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->text('refund_reason')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_id');
            $table->index('payment_method');
            $table->index('payment_status');
            $table->index('reference_number');
            $table->index(['spa_business_id', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
