<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A client's saved home address (Personal Information in the app): picked on a map once, then
// offered on every home-service booking instead of being set again each time. Same shape as the
// address an appointment keeps (2026_10_03_120000_add_home_service), which still keeps its own
// copy: changing the saved address later never moves a booking already made.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'home_address')) {
                $table->string('home_address', 500)->nullable();
                $table->string('home_address_notes', 500)->nullable();
                $table->decimal('home_latitude', 10, 7)->nullable();
                $table->decimal('home_longitude', 10, 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_address', 'home_address_notes', 'home_latitude', 'home_longitude']);
        });
    }
};
