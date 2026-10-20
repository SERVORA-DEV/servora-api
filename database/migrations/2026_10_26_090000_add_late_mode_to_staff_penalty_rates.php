<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How lateness is charged from that day on: 'day' — late_amount for
        // each late day; 'hour' — late_amount per hour late, for the minutes
        // they were actually late (App\Support\AttendancePenalty).
        if (Schema::hasTable('staff_penalty_rates') && ! Schema::hasColumn('staff_penalty_rates', 'late_mode')) {
            Schema::table('staff_penalty_rates', function (Blueprint $table) {
                $table->string('late_mode', 8)->default('day')->after('late_amount');
            });
        }
        Cache::forget('schema:staff_penalties');
        Cache::forget('schema:late_mode');
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_penalty_rates', 'late_mode')) {
            Schema::table('staff_penalty_rates', function (Blueprint $table) {
                $table->dropColumn('late_mode');
            });
        }
        Cache::forget('schema:late_mode');
    }
};
