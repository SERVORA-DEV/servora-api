<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The checkout_sessions table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('spa_business_id');
            $table->string('purpose', 20);
            $table->bigInteger('subscription_id')->nullable();
            $table->bigInteger('subscription_plan_id')->nullable();
            $table->string('billing_cycle', 16)->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->boolean('save_method')->default(false);
            $table->boolean('enable_auto_renew')->default(false);
            $table->bigInteger('payment_method_id')->nullable();
            $table->string('reference_id', 64);
            $table->string('xendit_session_id', 64)->nullable();
            $table->string('xendit_payment_request_id', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique('reference_id', 'checkout_sessions_reference_id_unique');
            $table->unique('uuid', 'checkout_sessions_uuid_unique');
            $table->index(['spa_business_id', 'status'], 'checkout_sessions_spa_business_id_status_index');
            $table->index('xendit_payment_request_id', 'checkout_sessions_xendit_payment_request_id_index');
            $table->index('xendit_session_id', 'checkout_sessions_xendit_session_id_index');
            $table->foreign('payment_method_id', 'checkout_sessions_payment_method_id_foreign')->references('id')->on('business_payment_methods')->onDelete('set null');
            $table->foreign('spa_business_id', 'checkout_sessions_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
            $table->foreign('subscription_id', 'checkout_sessions_subscription_id_foreign')->references('id')->on('subscriptions')->onDelete('set null');
            $table->foreign('subscription_plan_id', 'checkout_sessions_subscription_plan_id_foreign')->references('id')->on('subscription_plans')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
