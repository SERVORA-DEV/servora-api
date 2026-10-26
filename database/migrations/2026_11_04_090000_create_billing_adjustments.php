<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Changing a bill after it is paid (App\Support\BillAdjustment,
// App\Service\Business\BillingAdjustmentService): a voucher put on afterwards
// or a refund. Each one is a row here — applied at once, or waiting for a
// manager or the owner to approve — so the bill keeps who did what and why.
//
//   billing_adjustments            the change, the request and its answer
//   user_permissions.payment_adjust
//       "Adjust paid bills": applies at once instead of asking. A manager's
//       by default; a front desk account's only when the owner turns it on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('billing_id')->constrained('billings')->cascadeOnDelete();

            // voucher | refund
            $table->string('kind', 20);
            // A refund: what to hand back. A voucher: what it hands back
            // (worked out when asked, and again when it is applied).
            $table->decimal('amount', 12, 2)->default(0);
            // A voucher: which one, and its name as it was (App\Support\History).
            $table->foreignId('client_voucher_id')->nullable()->constrained('client_vouchers')->nullOnDelete();
            $table->string('voucher_name', 120)->nullable();
            $table->string('reason', 255);

            // pending | applied | declined | withdrawn
            $table->string('status', 20)->default('pending');
            // Who may answer while it waits: manager (a manager or the owner) | owner.
            $table->string('needs', 20)->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name', 160)->nullable();
            $table->string('requested_by_role', 30)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 160)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['billing_id', 'status']);
            $table->index(['spa_business_id', 'status']);
        });

        Schema::table('user_permissions', function (Blueprint $table) {
            $table->boolean('payment_adjust')->default(false);
        });

        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->where('role', 'manager')->select('id'))
            ->update(['payment_adjust' => true]);

        Cache::forget('schema:billing_adjustments');
    }

    public function down(): void
    {
        Schema::table('user_permissions', fn (Blueprint $table) => $table->dropColumn('payment_adjust'));
        Schema::dropIfExists('billing_adjustments');

        Cache::forget('schema:billing_adjustments');
    }
};
