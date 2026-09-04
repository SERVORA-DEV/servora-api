<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The original migration only indexed (staff_id, attendance_date), not
    // constrained it — one row per staff per day is a hard invariant the
    // whole feature relies on (AttendanceRepository::upsert), so this
    // replaces the plain index with a real unique constraint.
    public function up(): void
    {
        // Add the unique index before dropping the old plain one — MySQL
        // needs some index covering staff_id to back its foreign key
        // constraint at all times, so dropping the old index first (with
        // nothing yet in place to replace it) is rejected with error 1553.
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['staff_id', 'attendance_date'], 'attendances_staff_date_unique');
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['staff_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['staff_id', 'attendance_date']);
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_staff_date_unique');
        });
    }
};
