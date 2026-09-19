<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Front Desk check-in for a staff member with no StaffSchedule row today
    // (see FrontOfficeAttendanceService::checkIn) is tagged 'Fill In' rather
    // than 'Present', so it stays distinguishable from a normal scheduled
    // shift. Enum columns aren't alterable via Schema::table()->change() —
    // see PostgresSchema for why.
    public function up(): void
    {
        PostgresSchema::redefineEnum('attendances', 'status', [
            'Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday', 'Fill In',
        ], default: 'Present');
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('attendances', 'status', [
            'Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday',
        ], default: 'Present');
    }
};
