<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Same reasoning as appointment_services — a uuid is needed for
    // appointment_packages rows to be addressed the same way as every
    // other resource, even though this table is now purchase-record-only.
    public function up(): void
    {
        Schema::table('appointment_packages', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_packages', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
