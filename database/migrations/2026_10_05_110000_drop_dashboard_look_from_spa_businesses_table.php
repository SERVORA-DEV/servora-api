<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The "Dashboard look" settings (the business's logo in the sidebar, and
// dashboard colour palettes) were dropped. This removes the two columns the
// two migrations before this one added; nothing reads them any more.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['show_logo_in_sidebar', 'dashboard_palette'] as $column) {
            if (Schema::hasColumn('spa_businesses', $column)) {
                Schema::table('spa_businesses', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        Schema::table('spa_businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('spa_businesses', 'show_logo_in_sidebar')) {
                $table->boolean('show_logo_in_sidebar')->default(true);
            }
            if (! Schema::hasColumn('spa_businesses', 'dashboard_palette')) {
                $table->string('dashboard_palette', 40)->nullable();
            }
        });
    }
};
