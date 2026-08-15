<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Adds the MODULE 5 spec's fields to the facilities table created
    // 2026_08_09_060003 — merged rather than replaced, so the existing
    // type/capacity/status/amenities columns (Facilities & Rooms page) keep
    // working alongside the new description/is_available/service-linkage.
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_available')->default(true)->after('status');

            $table->unique(['spa_branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropUnique(['spa_branch_id', 'name']);
            $table->dropColumn(['description', 'is_available']);
        });
    }
};
