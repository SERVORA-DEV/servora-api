<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

// Front-desk attendance notices (a therapist who hasn't checked in, went
// home early, is on leave, or is still clocked in after their shift) get
// their own feed category instead of riding along under 'System'.
return new class extends Migration
{
    public function up(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification', 'Registration', 'Attendance',
        ]);
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification', 'Registration',
        ]);
    }
};
