<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Business Settings → Business identity → Dashboard look: the colour palette
// the owner picked for the business's dashboards and staff sign-in page. The
// id of a palette defined on the web side (utils/brandPalettes.ts); empty
// means Servora's own colours.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->string('dashboard_palette', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropColumn('dashboard_palette');
        });
    }
};
