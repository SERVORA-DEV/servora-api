<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('spa_businesses')
            ->where('verification_status', 'Suspended')
            ->update([
                'verification_status' => 'Verified',
                'suspension_reason' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Restored businesses must stay available if the code is rolled back.
    }
};
