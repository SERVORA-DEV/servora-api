<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Schedules no longer have a break: not a staff member's week, not a
// branch's opening hours. Nothing reads or writes these columns any more, so
// the app works the same before and after this runs.
//
// The break times saved in them are deleted and cannot be brought back —
// down() only puts the empty columns back.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->dropColumn(['break_start', 'break_end']);
        });

        Schema::table('branch_schedules', function (Blueprint $table) {
            $table->dropColumn(['break_start', 'break_end']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
        });

        Schema::table('branch_schedules', function (Blueprint $table) {
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
        });
    }
};
