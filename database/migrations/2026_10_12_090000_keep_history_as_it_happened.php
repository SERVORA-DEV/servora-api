<?php

use App\Service\Business\HistoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// History stays as it happened (App\Support\History): each record of
// something that took place gets its own copy of what it was at the time, so
// editing or removing a service, a room, a therapist, a package, a program or
// a setting afterwards never rewrites it.
//
// Every column is nullable: a record with no copy falls back to the live
// value. Existing records are filled once, at the end, from things as they
// stand today (HistoryBackfill) — the only record there is.
return new class extends Migration
{
    public function up(): void
    {
        // A service on a visit: what was booked (the price was already kept).
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->string('service_name')->nullable()->after('service_variant_id');
            $table->unsignedInteger('duration_minutes')->nullable()->after('service_name');
            $table->string('service_type', 20)->nullable()->after('duration_minutes');
            $table->string('service_category')->nullable()->after('service_type');
        });

        Schema::table('appointment_packages', function (Blueprint $table) {
            $table->string('package_name')->nullable()->after('package_id');
        });

        // Who did it, where — and what they earned for it once completed.
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->string('staff_name')->nullable()->after('staff_id');
            $table->string('facility_name')->nullable()->after('facility_id');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('assignment_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('received_by_name')->nullable()->after('received_by');
        });

        // How the day turned out: the shift and lateness from the check-in,
        // the hours and overtime from the check-out.
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('scheduled_start', 5)->nullable()->after('status');
            $table->string('scheduled_end', 5)->nullable()->after('scheduled_start');
            $table->unsignedInteger('late_minutes')->nullable()->after('scheduled_end');
            $table->unsignedInteger('work_minutes')->nullable()->after('late_minutes');
            $table->unsignedInteger('overtime_minutes')->nullable()->after('work_minutes');
            $table->string('resolved_status', 30)->nullable()->after('overtime_minutes');
        });

        // The deal a voucher was issued with; what a membership was sold as.
        Schema::table('client_vouchers', function (Blueprint $table) {
            $table->string('program_name')->nullable()->after('customer_program_id');
            $table->json('deal')->nullable()->after('program_name');
        });

        // (client_memberships.perks is the column History::ready() looks for
        // last — keep it the final one added.)
        Schema::table('client_memberships', function (Blueprint $table) {
            $table->string('program_name')->nullable()->after('customer_program_id');
            $table->json('perks')->nullable()->after('program_name');
        });

        Cache::forget('schema:history');

        app(HistoryBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('client_memberships', fn (Blueprint $table) => $table->dropColumn(['program_name', 'perks']));
        Schema::table('client_vouchers', fn (Blueprint $table) => $table->dropColumn(['program_name', 'deal']));
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn(['scheduled_start', 'scheduled_end', 'late_minutes', 'work_minutes', 'overtime_minutes', 'resolved_status']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('received_by_name'));
        Schema::table('therapist_assignments', fn (Blueprint $table) => $table->dropColumn(['staff_name', 'facility_name', 'commission_amount']));
        Schema::table('appointment_packages', fn (Blueprint $table) => $table->dropColumn('package_name'));
        Schema::table('appointment_services', fn (Blueprint $table) => $table->dropColumn(['service_name', 'duration_minutes', 'service_type', 'service_category']));

        Cache::forget('schema:history');
    }
};
