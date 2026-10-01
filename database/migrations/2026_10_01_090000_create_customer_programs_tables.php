<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Customer programs, persisted (until now they lived only in the web app's
// memory). A business defines them once; each branch chooses which it offers:
//
//   customer_programs          loyalty (one per business) · voucher · discount · membership
//   customer_program_branch    which branches offer a program
//   customer_program_items     the vouchers/discounts a membership bundles
//
// And what each client holds (clients rows are per business):
//
//   client_point_entries       points ledger — earn rows keep `remaining` so
//                              redemptions/expiry spend the oldest first
//   client_vouchers            vouchers in a client's wallet
//   client_memberships         memberships sold at the front desk
//
// Also:
//   spa_business_settings.programs   the module switch (Business Settings)
//   billings.discount_program_id / client_voucher_id   what a bill's discount came from
//   billings.billing_type += 'Membership'             a membership sale is a bill
//   reward_view / reward_update granted to managers and front officers, now
//   that the rewards routes enforce them (front desk sells memberships and
//   applies vouchers).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_business_settings', function (Blueprint $table) {
            $table->json('programs')->nullable()->after('notifications');
        });

        Schema::create('customer_programs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->string('audience', 10)->default('all');
            $table->json('values')->nullable();
            $table->string('condition_kind', 20)->nullable();
            $table->unsignedInteger('condition_value')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_business_id', 'type']);
        });

        Schema::create('customer_program_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_program_id')->constrained('customer_programs')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_program_id', 'spa_branch_id']);
        });

        Schema::create('customer_program_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_program_id')->constrained('customer_programs')->cascadeOnDelete();
            $table->foreignId('item_program_id')->constrained('customer_programs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_program_id', 'item_program_id']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('discount_program_id')->nullable()->after('discounted_by')->constrained('customer_programs')->nullOnDelete();
        });
        PostgresSchema::redefineEnum('billings', 'billing_type', ['Subscription', 'Appointment', 'Membership'], nullable: true);

        Schema::create('client_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('customer_program_id')->constrained('customer_programs')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('active');
            $table->decimal('price', 10, 2)->default(0);
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['spa_branch_id', 'status']);
        });

        Schema::create('client_vouchers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('customer_program_id')->constrained('customer_programs')->cascadeOnDelete();
            $table->string('source', 20);
            $table->foreignId('source_billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->foreignId('client_membership_id')->nullable()->constrained('client_memberships')->cascadeOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('available');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['customer_program_id', 'source']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('client_voucher_id')->nullable()->after('discount_program_id')->constrained('client_vouchers')->nullOnDelete();
        });

        Schema::create('client_point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->string('type', 10);
            $table->integer('points');
            $table->unsignedInteger('remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'type']);
        });
        // A bill credits points once, however many times it settles.
        DB::statement("CREATE UNIQUE INDEX client_point_entries_bill_earn_unique ON client_point_entries (billing_id) WHERE type = 'earn' AND billing_id IS NOT NULL");

        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->whereIn('role', ['manager', 'front_officer'])->select('id'))
            ->update(['reward_view' => true, 'reward_update' => true]);
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_voucher_id');
            $table->dropConstrainedForeignId('discount_program_id');
        });
        DB::table('billings')->where('billing_type', 'Membership')->update(['billing_type' => null]);
        PostgresSchema::redefineEnum('billings', 'billing_type', ['Subscription', 'Appointment'], nullable: true);

        Schema::dropIfExists('client_point_entries');
        Schema::dropIfExists('client_vouchers');
        Schema::dropIfExists('client_memberships');
        Schema::dropIfExists('customer_program_items');
        Schema::dropIfExists('customer_program_branch');
        Schema::dropIfExists('customer_programs');

        Schema::table('spa_business_settings', function (Blueprint $table) {
            $table->dropColumn('programs');
        });
    }
};
