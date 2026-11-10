<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->string('service_location', 20)->default('In Spa');
            $table->dropUnique(['service_id', 'duration_minutes']);

        });
        DB::statement('CREATE UNIQUE INDEX service_variant_location_duration_unique ON service_variants (service_id, service_location, duration_minutes) WHERE deleted_at IS NULL');
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('home_service_available')->default(false);
            $table->decimal('home_price', 10, 2)->nullable();
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->unsignedInteger('home_duration_minutes')->nullable();
            $table->decimal('home_price', 10, 2)->nullable();
        });
        Schema::table('branch_packages', fn (Blueprint $table) => $table->decimal('home_custom_price', 10, 2)->nullable());
        // No backfill: owners must explicitly review home prices. Existing
        // appointments, receipts and historical fee earnings are untouched.
    }

    public function down(): void
    {
        if (DB::table('service_variants')->where('service_location', 'Home Service')->exists()) {
            throw new RuntimeException('Home-service prices exist. Review their historical references before rolling back.');
        }
        Schema::table('branch_packages', fn (Blueprint $table) => $table->dropColumn('home_custom_price'));
        Schema::table('package_services', fn (Blueprint $table) => $table->dropColumn(['home_duration_minutes', 'home_price']));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['home_service_available', 'home_price']));
        DB::statement('DROP INDEX service_variant_location_duration_unique');
        Schema::table('service_variants', function (Blueprint $table) {
            $table->unique(['service_id', 'duration_minutes']);
            $table->dropColumn('service_location');
        });
    }
};
