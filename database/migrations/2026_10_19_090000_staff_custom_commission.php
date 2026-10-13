<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A commission of their own for a staff member (for experience, say). The
// rate in Settings → Staff Policies stays as the STANDARD one, earned by
// everyone who has none of their own; a custom rate is the same kind as the
// standard (a percentage, or pesos per service) — App\Support\CommissionShare.
//
// The rate an amount was worked out at is kept beside it on the assignment,
// so an earnings line can say what it was (App\Support\History).
//
// Until this has run everyone earns the standard rate and no custom one is
// saved or shown (App\Models\Staff::hasCustomCommission).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // Null: the standard rate.
            $table->decimal('commission_rate', 10, 2)->nullable()->after('role');
        });

        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->decimal('commission_rate', 10, 2)->nullable()->after('commission_amount');
            // 'percentage' | 'fixed'
            $table->string('commission_type', 12)->nullable()->after('commission_rate');
        });

        Cache::forget('schema:staff_commission');
    }

    public function down(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_type']);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });

        Cache::forget('schema:staff_commission');
    }
};
