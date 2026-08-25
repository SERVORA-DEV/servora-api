<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Same reasoning as appointment_services — a uuid is needed to address
    // (or cancel) one specific therapist/room assignment row directly.
    public function up(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
