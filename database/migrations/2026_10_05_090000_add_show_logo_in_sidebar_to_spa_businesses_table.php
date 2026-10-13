<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Business Settings → Business identity: whether the dashboards' sidebar shows
// the business's own logo and name in place of Servora's. On unless the owner
// turns it off; it only has an effect once a logo is uploaded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->boolean('show_logo_in_sidebar')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            $table->dropColumn('show_logo_in_sidebar');
        });
    }
};
