<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Front Desk check-in for a staff member with no StaffSchedule row today
    // (see FrontOfficeAttendanceService::checkIn) is tagged 'Fill In' rather
    // than 'Present', so it stays distinguishable from a normal scheduled
    // shift. Enum columns aren't alterable via Schema::table()->change(), so
    // this goes straight to SQL.
    public function up(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday', 'Fill In') NOT NULL DEFAULT 'Present'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendances MODIFY status ENUM('Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday') NOT NULL DEFAULT 'Present'");
    }
};
