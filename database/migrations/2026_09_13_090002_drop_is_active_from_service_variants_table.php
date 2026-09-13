<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A variant isn't its own thing to activate/deactivate — it's a
    // duration/price option of a Service, so its status is now always read
    // straight from the parent Service's is_active (see
    // ServiceVariantResource) instead of carrying its own, independently
    // settable copy of the same concept.
    public function up(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }
};
