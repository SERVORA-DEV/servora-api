<?php

use App\Support\HomeVisitStart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Three things that arrive together:
//
//   • Starting a home visit by scanning (App\Support\HomeVisitStart): every
//     home booking has a start code, shown to the client as a QR; each branch
//     has a scanner link of its own that only starts its own bookings.
//   • What a spa records about a client: gender, and their age (kept as a
//     birth year so it doesn't go stale) — App\Support\ClientProfile.
//   • The staff gender a booking asked for (App\Support\StaffGender).
//
// Until this has run none of it is offered, asked for or required
// (Appointment::hasHomeStart, Client::hasProfile).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('home_start_code', 12)->nullable()->unique()->after('home_service_fee');
            // When the staff member's scan started the visit.
            $table->timestamp('home_scanned_at')->nullable()->after('home_start_code');
            // 'Male' | 'Female' | null (no preference).
            $table->string('preferred_staff_gender', 10)->nullable()->after('home_scanned_at');
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            // The secret in this branch's scanner link. Null until the front
            // desk first opens it; replaced to cut an old link off.
            $table->string('scanner_token', 64)->nullable()->unique();
        });

        Schema::table('clients', function (Blueprint $table) {
            // 'Male' | 'Female' | null (not recorded yet).
            $table->string('gender', 10)->nullable()->after('last_name');
            $table->unsignedSmallInteger('birth_year')->nullable()->after('gender');
        });

        // Home bookings already made and still to happen get a code too.
        DB::table('appointments')
            ->where('service_location', 'Home Service')
            ->whereIn('status', ['scheduled', 'checked_in'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) {
                do {
                    $code = HomeVisitStart::newCode();
                } while (DB::table('appointments')->where('home_start_code', $code)->exists());
                DB::table('appointments')->where('id', $id)->update(['home_start_code' => $code]);
            });

        Cache::forget('schema:home_visit_start');
        Cache::forget('schema:client_profile');
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['home_start_code']);
            $table->dropColumn(['home_start_code', 'home_scanned_at', 'preferred_staff_gender']);
        });

        Schema::table('spa_branches', function (Blueprint $table) {
            $table->dropUnique(['scanner_token']);
            $table->dropColumn('scanner_token');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birth_year']);
        });

        Cache::forget('schema:home_visit_start');
        Cache::forget('schema:client_profile');
    }
};
