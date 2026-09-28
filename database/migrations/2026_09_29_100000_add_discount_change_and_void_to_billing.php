<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Front-desk billing: discounts, cash change and voiding a mistaken payment.
//
// - billings: `amount` stays what's owed; `subtotal` is the pre-discount
//   total it came from (backfilled = amount for existing bills), with the
//   discount's type/value/computed amount, reason and who applied it.
// - payments: for cash, what the client handed over and the change given
//   back; `amount` stays what was actually applied to the bill.
// - payments.payment_status gains 'Voided' — a payment entered by mistake,
//   excluded from the paid total (unlike a refund, which records money
//   actually returned against a real payment via refunded_amount).
//
// Also grants billing_update (discounts) and payment_refund (void/refund) to
// existing manager accounts, now that they're in the manager bundle.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->nullable()->after('billing_number');
            $table->string('discount_type', 10)->nullable()->after('subtotal');
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value');
            $table->string('discount_reason', 255)->nullable()->after('discount_amount');
            $table->foreignId('discounted_by')->nullable()->after('discount_reason')->constrained('users')->nullOnDelete();
        });
        DB::table('billings')->whereNull('subtotal')->update(['subtotal' => DB::raw('amount')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount_tendered', 10, 2)->nullable()->after('amount');
            $table->decimal('change_given', 10, 2)->nullable()->after('amount_tendered');
            $table->timestamp('voided_at')->nullable()->after('refund_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->after('voided_by')->constrained('users')->nullOnDelete();
        });

        PostgresSchema::redefineEnum('payments', 'payment_status', ['Pending', 'Paid', 'Failed', 'Refunded', 'Voided'], default: 'Pending');

        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->where('role', 'manager')->select('id'))
            ->update(['billing_update' => true, 'payment_refund' => true]);
    }

    public function down(): void
    {
        DB::table('payments')->where('payment_status', 'Voided')->update(['payment_status' => 'Failed']);
        PostgresSchema::redefineEnum('payments', 'payment_status', ['Pending', 'Paid', 'Failed', 'Refunded'], default: 'Pending');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['amount_tendered', 'change_given', 'voided_at']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discounted_by');
            $table->dropColumn(['subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason']);
        });
    }
};
