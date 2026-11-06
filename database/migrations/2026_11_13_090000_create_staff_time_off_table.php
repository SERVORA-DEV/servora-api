<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Time off for one member of staff — leave, sick days, absences — by date or
// date range, on top of their weekly schedule (Employees → Schedule → Time off).
// A new table only: nothing that exists today is changed. Until this has run,
// no one has time off and the screen says it isn't ready (StaffTimeOff::ready).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            // leave · sick · absent · other
            $table->string('type', 20);
            // Both days count: one day off has starts_on = ends_on.
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note', 120)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['staff_id', 'ends_on']);
        });

        Cache::forget('schema:staff_time_off');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');

        Cache::forget('schema:staff_time_off');
    }
};
