<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A service can need a facility (a room, a sauna) before the front desk may
// start it — App\Support\ServiceKind::needsFacility. A facility-based service
// always does; a massage can; a haircut or nails usually doesn't.
//
// The booking keeps its own copy of what it was booked as (App\Support\History),
// so editing the service later doesn't change visits already booked. Until
// this has run nothing needs a facility (App\Models\Service::hasFacilityRequirement).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('facility_required')->default(false)->after('service_type');
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            // null = booked before this existed: read from the service.
            $table->boolean('facility_required')->nullable()->after('service_type');
        });

        // What already is facility-based always needed one.
        DB::table('services')->where('service_type', 'facility')->update(['facility_required' => true]);

        Cache::forget('schema:facility_required');
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn('facility_required');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('facility_required');
        });

        Cache::forget('schema:facility_required');
    }
};
