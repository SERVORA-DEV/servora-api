<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The billings table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billings')) {
            return;
        }

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('subscription_id')->nullable();
            $table->bigInteger('appointment_id')->nullable();
            $table->bigInteger('spa_business_id')->nullable();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->enum('billing_type', ['Subscription', 'Appointment', 'Membership'])->nullable();
            $table->string('billing_number', 50);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['Pending', 'Paid', 'Overdue', 'Cancelled', 'Refunded'])->default('Pending');
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('payment_reminder_sent_at')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->bigInteger('discounted_by')->nullable();
            $table->decimal('vat_amount', 10, 2)->nullable();
            $table->bigInteger('discount_program_id')->nullable();
            $table->bigInteger('client_voucher_id')->nullable();
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->unique('billing_number', 'billings_billing_number_unique');
            $table->unique('uuid', 'billings_uuid_unique');
            $table->index('appointment_id', 'billings_appointment_id_index');
            $table->index(['billing_type', 'issued_at'], 'billings_billing_type_issued_at_index');
            $table->index(['spa_branch_id', 'status'], 'billings_spa_branch_id_status_index');
            $table->index(['spa_business_id', 'status'], 'billings_spa_business_id_status_index');
            $table->index('subscription_id', 'billings_subscription_id_index');
            $table->foreign('appointment_id', 'billings_appointment_id_foreign')->references('id')->on('appointments')->onDelete('set null');
            $table->foreign('discount_program_id', 'billings_discount_program_id_foreign')->references('id')->on('customer_programs')->onDelete('set null');
            $table->foreign('discounted_by', 'billings_discounted_by_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
