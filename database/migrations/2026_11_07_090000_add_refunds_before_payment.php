<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Refunds before payment (App\Support\BillAdjustment, kind "credit"): on a
// bill not paid yet, money taken off what the client owes — for one service
// or the whole bill — under the same refund policies, approvals and "comes
// out of" as a refund after payment.
//
//   billings.credit_amount
//       what has been taken off this way, on top of any discount:
//       amount = subtotal − discount − credit_amount
//   billing_adjustments.appointment_service_id / service_name
//       the service it was for (empty: the whole bill), with its name as it
//       was (App\Support\History)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('credit_amount', 12, 2)->default(0);
        });

        Schema::table('billing_adjustments', function (Blueprint $table) {
            $table->foreignId('appointment_service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->string('service_name', 255)->nullable();
        });

        Cache::forget('schema:refunds_before_payment');
    }

    public function down(): void
    {
        Schema::table('billing_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_service_id');
            $table->dropColumn('service_name');
        });
        Schema::table('billings', fn (Blueprint $table) => $table->dropColumn('credit_amount'));

        Cache::forget('schema:refunds_before_payment');
    }
};
