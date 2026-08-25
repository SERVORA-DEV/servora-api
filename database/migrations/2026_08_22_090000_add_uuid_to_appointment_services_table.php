<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // appointment_services was created without a uuid — every other
    // sub-resource in this codebase is addressed by uuid, never a raw id,
    // so per-service actions (assign therapist, start, complete) need one.
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
