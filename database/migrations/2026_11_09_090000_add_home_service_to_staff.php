<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// "Available for home service" on each staff member: the owner's tick for who
// may be sent to a client's home. Only staff with it on can be chosen for a
// home service visit (App\Service\Business\HomeServiceRules). Off by default,
// like services.home_service_available — nobody is sent out until the owner
// turns it on for them. Until this has run nobody is held back from a home
// visit (App\Models\Staff::hasHomeService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('home_service_available')->default(false)->after('specialties');
        });

        Cache::forget('schema:staff_home_service');
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('home_service_available');
        });

        Cache::forget('schema:staff_home_service');
    }
};
