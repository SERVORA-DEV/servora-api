<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The subscriptions table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions')) {
            return;
        }

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id')->nullable();
            $table->bigInteger('subscription_plan_id');
            $table->enum('billing_cycle', ['Monthly', 'Yearly'])->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->enum('status', ['Pending', 'Active', 'Expired', 'Cancelled'])->default('Pending');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('expiry_reminder_sent_at')->nullable();
            $table->bigInteger('pending_plan_id')->nullable();
            $table->string('plan_change_status', 16)->nullable();
            $table->timestamp('plan_change_responded_at')->nullable();
            $table->bigInteger('scheduled_plan_id')->nullable();
            $table->string('scheduled_billing_cycle', 16)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->bigInteger('payment_method_id')->nullable();
            $table->integer('renewal_attempts')->default(0);
            $table->timestamp('last_renewal_attempt_at')->nullable();
            $table->string('renewal_failure_reason')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->json('scheduled_keep')->nullable();
            $table->integer('extra_branches')->default(0);
            $table->integer('extra_accounts')->default(0);
            $table->decimal('base_paid', 12, 2)->nullable();
            $table->decimal('extras_paid', 12, 2)->default(0);
            $table->json('scheduled_extras')->nullable();
            $table->unique('uuid', 'subscriptions_uuid_unique');
            $table->index(['spa_business_id', 'status'], 'subscriptions_spa_business_id_status_index');
            $table->index(['starts_at', 'expires_at'], 'subscriptions_starts_at_expires_at_index');
            $table->foreign('payment_method_id', 'subscriptions_payment_method_id_foreign')->references('id')->on('business_payment_methods')->onDelete('set null');
            $table->foreign('pending_plan_id', 'subscriptions_pending_plan_id_foreign')->references('id')->on('subscription_plans')->onDelete('set null');
            $table->foreign('scheduled_plan_id', 'subscriptions_scheduled_plan_id_foreign')->references('id')->on('subscription_plans')->onDelete('set null');
            $table->foreign('subscription_plan_id', 'subscriptions_subscription_plan_id_foreign')->references('id')->on('subscription_plans');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
