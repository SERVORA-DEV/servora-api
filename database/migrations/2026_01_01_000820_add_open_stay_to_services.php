<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Open stay" for a facility-based service (a sauna): the client stays as long as they like for
// one price. Its lengths are then only a usual stay, used to plan the facility and closing time,
// never a limit; the visit ends when the desk checks the client out (Service::isOpenStay).
// services.open_stay is the owner's setting; appointment_services.open_stay is each booked
// line's own copy (App\Support\Activity\History). Off for everything there is; safe to run again.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'open_stay')) {
            Schema::table('services', function (Blueprint $table) {
                $table->boolean('open_stay')->default(false);
            });
        }
        if (! Schema::hasColumn('appointment_services', 'open_stay')) {
            Schema::table('appointment_services', function (Blueprint $table) {
                $table->boolean('open_stay')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointment_services', 'open_stay')) {
            Schema::table('appointment_services', fn (Blueprint $table) => $table->dropColumn('open_stay'));
        }
        if (Schema::hasColumn('services', 'open_stay')) {
            Schema::table('services', fn (Blueprint $table) => $table->dropColumn('open_stay'));
        }
    }
};
