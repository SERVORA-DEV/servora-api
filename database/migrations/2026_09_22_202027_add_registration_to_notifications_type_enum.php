<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

// The admin notification feed (Settings > Notifications on the frontend)
// has a "Registration" category alongside Verification/Subscription/Payment/
// System — branch-registration-submitted notifications belong there, not
// under the generic 'System' type they were mistakenly created with.
return new class extends Migration
{
    public function up(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification', 'Registration',
        ]);
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification',
        ]);
    }
};
