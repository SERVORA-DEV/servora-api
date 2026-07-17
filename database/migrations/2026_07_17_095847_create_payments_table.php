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
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('payment_method', [
                'Cash',
                'GCash',
                'Maya',
                'Bank Transfer',
                'Credit Card',
                'Debit Card'
            ]);

            $table->string('reference_number', 100)
                ->nullable();

            $table->decimal('amount', 10, 2);

            $table->enum('payment_status', [
                'Pending',
                'Paid',
                'Failed',
                'Refunded',
            ])->default('Pending');

            $table->timestamp('paid_at')
                ->nullable();

            $table->text('remarks')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_id');
            $table->index('payment_method');
            $table->index('payment_status');
            $table->index('reference_number');
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