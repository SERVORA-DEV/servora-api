<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Front-desk leave & cover: a therapist who goes home early is flagged
// left_early (their check_out_at is when they left, the reason goes in
// remarks), and whoever fills in for them — often a lower-earning therapist
// picking up extra commission on a day they weren't scheduled — records who
// they are covering for on their own row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('left_early')->default(false)->after('check_out_at');
            $table->foreignId('covering_for_staff_id')->nullable()->after('left_early')
                ->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('covering_for_staff_id');
            $table->dropColumn('left_early');
        });
    }
};
