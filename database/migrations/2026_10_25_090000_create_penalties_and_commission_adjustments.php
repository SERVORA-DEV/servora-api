<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a late day and an absent day cost, and from which day on.
        // Staff Policies holds the amounts in force now; every change adds a
        // row here, so a day keeps the penalty that was in force on it
        // (App\Support\History) — absent days included, which have no
        // attendance record of their own to keep it on.
        Schema::create('staff_penalty_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->boolean('late_enabled')->default(false);
            $table->decimal('late_amount', 10, 2)->default(0);
            $table->boolean('absent_enabled')->default(false);
            $table->decimal('absent_amount', 10, 2)->default(0);
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['spa_business_id', 'effective_from']);
        });

        // What was decided about one staff member's commission on a visit the
        // client complained about and was refunded for (BillingService::
        // complaintRefund). It keeps its own copy of who, what and how much.
        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('billings')->nullOnDelete();
            $table->foreignId('therapist_assignment_id')->constrained('therapist_assignments')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            // none: no commission; percent: `value`% of it; fixed: `value` pesos.
            $table->string('mode', 10);
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('original_amount', 10, 2);
            $table->decimal('adjusted_amount', 10, 2);

            $table->string('reason', 255);
            $table->decimal('refund_amount', 10, 2)->default(0);

            $table->string('staff_name', 255)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->string('appointment_number', 64)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['therapist_assignment_id', 'id']);
            $table->index(['staff_id', 'created_at']);
        });

        // Every business starts from what its Staff Policies say now (off
        // unless an owner set it before this ran), from the beginning.
        DB::table('spa_businesses')->orderBy('id')->select('id')->each(function ($business) {
            $raw = DB::table('spa_business_settings')->where('spa_business_id', $business->id)->value('staff_policy');
            $policy = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            DB::table('staff_penalty_rates')->insert([
                'spa_business_id' => $business->id,
                'late_enabled' => (bool) ($policy['late_penalty_enabled'] ?? false),
                'late_amount' => (float) ($policy['late_penalty_amount'] ?? 0),
                'absent_enabled' => (bool) ($policy['absent_penalty_enabled'] ?? false),
                'absent_amount' => (float) ($policy['absent_penalty_amount'] ?? 0),
                'effective_from' => '2000-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Cache::forget('schema:staff_penalties');
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('staff_penalty_rates');
        Cache::forget('schema:staff_penalties');
    }
};
