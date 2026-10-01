<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Servora's own subscription checkout (CheckoutService), paid through Xendit
// Payment Sessions in COMPONENTS mode — card, GCash and Maya fields are
// Xendit-hosted iframes embedded in our page, so card numbers never reach
// Servora. Adds:
//  - business_payment_methods: saved (tokenized) card/GCash/Maya accounts,
//    charged for auto-renewal;
//  - business_billing_addresses: the billing address on the checkout;
//  - checkout_sessions: one per payment attempt, the durable record of what
//    a payment is for until Xendit confirms it;
//  - VAT on subscription invoices, and auto-renew bookkeeping.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->string('type', 16); // CARD | GCASH | MAYA
            $table->string('xendit_payment_token_id', 64)->unique();
            $table->string('label', 120);
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('status', 16)->default('active'); // active | failed
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_business_id', 'status']);
        });

        Schema::create('business_billing_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->unique()->constrained('spa_businesses')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20);
            $table->string('country', 2)->default('PH');
            $table->boolean('is_business_purchase')->default(false);
            $table->string('business_name', 150)->nullable();
            $table->string('tin', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            // subscribe | renew | upgrade | save_method | auto_renew
            $table->string('purpose', 20);
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_cycle', 16)->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->boolean('save_method')->default(false);
            $table->boolean('enable_auto_renew')->default(false);
            $table->foreignId('payment_method_id')->nullable()->constrained('business_payment_methods')->nullOnDelete();
            $table->string('reference_id', 64)->unique();
            $table->string('xendit_session_id', 64)->nullable()->index();
            $table->string('xendit_payment_request_id', 64)->nullable()->index();
            // pending | completed | failed | expired
            $table->string('status', 16)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('vat_amount', 10, 2)->nullable()->after('amount');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('auto_renew')
                ->constrained('business_payment_methods')->nullOnDelete();
            $table->unsignedInteger('renewal_attempts')->default(0)->after('payment_method_id');
            $table->timestamp('last_renewal_attempt_at')->nullable()->after('renewal_attempts');
            $table->string('renewal_failure_reason', 255)->nullable()->after('last_renewal_attempt_at');
        });

        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->string('xendit_customer_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', fn (Blueprint $table) => $table->dropColumn('xendit_customer_id'));
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['renewal_attempts', 'last_renewal_attempt_at', 'renewal_failure_reason']);
        });
        Schema::table('billings', fn (Blueprint $table) => $table->dropColumn('vat_amount'));
        Schema::dropIfExists('checkout_sessions');
        Schema::dropIfExists('business_billing_addresses');
        Schema::dropIfExists('business_payment_methods');
    }
};
