<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What each member of staff does (App\Support\StaffSpecialty): a nail
// technician, a hair stylist, a massage therapist — several per person, the
// first being their main one. "Staff" is everyone who performs services; the
// role is still saved as 'therapist'. Until this has run nobody has a
// specialty and none is shown (App\Models\Staff::hasSpecialties).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->json('specialties')->nullable()->after('role');
        });

        // Everyone who performs services starts as "Therapist", which is what
        // they have been called until now.
        DB::table('staff')->where('role', 'therapist')->update(['specialties' => json_encode(['Therapist'])]);

        Cache::forget('schema:staff_specialties');
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('specialties');
        });

        Cache::forget('schema:staff_specialties');
    }
};
