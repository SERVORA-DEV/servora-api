<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The payments table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('billing_id');
            $table->bigInteger('spa_business_id')->nullable();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->enum('payment_method', ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Online Banking', 'Credit Card', 'Debit Card', 'QR Code', 'Other'])->nullable();
            $table->string('gateway_provider', 50)->nullable();
            $table->string('gateway_reference', 150)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('payment_status', ['Pending', 'Paid', 'Failed', 'Refunded', 'Voided'])->default('Pending');
            $table->timestamp('paid_at')->nullable();
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->text('refund_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->decimal('amount_tendered', 10, 2)->nullable();
            $table->decimal('change_given', 10, 2)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->bigInteger('voided_by')->nullable();
            $table->bigInteger('received_by')->nullable();
            $table->string('received_by_name')->nullable();
            $table->unique('uuid', 'payments_uuid_unique');
            $table->index('billing_id', 'payments_billing_id_index');
            $table->index('payment_method', 'payments_payment_method_index');
            $table->index('payment_status', 'payments_payment_status_index');
            $table->index(['payment_status', 'paid_at'], 'payments_payment_status_paid_at_index');
            $table->index('reference_number', 'payments_reference_number_index');
            $table->index(['spa_branch_id', 'payment_status', 'paid_at'], 'payments_spa_branch_id_payment_status_paid_at_index');
            $table->index(['spa_business_id', 'paid_at'], 'payments_spa_business_id_paid_at_index');
            $table->foreign('billing_id', 'payments_billing_id_foreign')->references('id')->on('billings');
            $table->foreign('received_by', 'payments_received_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('voided_by', 'payments_voided_by_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
