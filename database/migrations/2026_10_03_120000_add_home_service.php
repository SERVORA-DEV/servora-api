<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Home service: a booking where the therapist goes to the client. See
// App\Service\Business\HomeServiceRules.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // 'In Spa' | 'Home Service'
            $table->string('service_location', 20)->default('In Spa')->after('appointment_type');
            $table->string('home_address', 500)->nullable()->after('service_location');
            $table->string('home_address_notes', 500)->nullable()->after('home_address');
            $table->decimal('home_latitude', 10, 7)->nullable()->after('home_address_notes');
            $table->decimal('home_longitude', 10, 7)->nullable()->after('home_latitude');
            // Straight-line distance from the branch when it was booked.
            $table->decimal('home_distance_km', 6, 2)->nullable()->after('home_longitude');
            // Charged on top of the services; goes to the therapist(s).
            $table->decimal('home_service_fee', 10, 2)->default(0)->after('home_distance_km');
        });

        Schema::table('services', function (Blueprint $table) {
            // The owner's "can be done at a client's home" tick.
            $table->boolean('home_service_available')->default(false)->after('is_active');
        });

        // The home-service switch was stored as "on" by default but nothing
        // ever read it. Now that it is real, every business and branch starts
        // with it off, so nothing is offered until an owner turns it on.
        DB::table('spa_business_settings')->whereNotNull('booking_defaults')->orderBy('id')->each(function ($row) {
            $defaults = json_decode($row->booking_defaults, true);
            if (is_array($defaults)) {
                $defaults['home_service_enabled'] = false;
                DB::table('spa_business_settings')->where('id', $row->id)->update(['booking_defaults' => json_encode($defaults)]);
            }
        });

        DB::table('spa_branches')->whereNotNull('booking_overrides')->orderBy('id')->each(function ($row) {
            $overrides = json_decode($row->booking_overrides, true);
            if (is_array($overrides) && array_key_exists('home_service_enabled', $overrides)) {
                unset($overrides['home_service_enabled']);
                DB::table('spa_branches')->where('id', $row->id)->update(['booking_overrides' => json_encode((object) $overrides)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['service_location', 'home_address', 'home_address_notes', 'home_latitude', 'home_longitude', 'home_distance_km', 'home_service_fee']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('home_service_available');
        });
    }
};
