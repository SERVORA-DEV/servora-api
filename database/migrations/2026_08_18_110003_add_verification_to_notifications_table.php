<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification',
        ]);
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('notifications', 'type', [
            'System', 'Appointment', 'Payment', 'Reward', 'Subscription',
        ]);
    }
};
