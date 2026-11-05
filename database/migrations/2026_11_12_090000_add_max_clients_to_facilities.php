<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // How many clients a facility holds at once. Every existing room stays at
    // one, so it behaves as it always has (Facility::clientLimit).
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_clients')->default(1)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('max_clients');
        });
    }
};
