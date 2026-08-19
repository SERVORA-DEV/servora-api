<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notifications MODIFY type
            ENUM('System','Appointment','Payment','Reward','Subscription','Verification') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE notifications MODIFY type
            ENUM('System','Appointment','Payment','Reward','Subscription') NOT NULL");
    }
};
