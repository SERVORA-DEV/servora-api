<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Same reasoning as therapist_assignments — a uuid is needed to address
    // (or clear) one specific attendance row directly, since attendances was
    // originally shipped with only an auto-increment id.
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
