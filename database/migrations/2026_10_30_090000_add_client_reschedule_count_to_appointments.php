<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How many times the client has moved this booking themselves in the app,
// for the "reschedules per booking" limit (Business Defaults → Booking, or a
// branch's own). The front desk's moves are not counted. Until this has been
// run nothing is counted and nothing is limited (Appointment::countsReschedules).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('client_reschedule_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('client_reschedule_count');
        });
    }
};
