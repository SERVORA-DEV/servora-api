<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // service_variant_id was left loose (no FK) because service_variants
    // didn't exist yet when appointment_services was created — it does now
    // (see 2026_08_18_090000_create_service_variants_and_migrate_service_data).
    // restrictOnDelete(), not cascade: a variant with billed appointment
    // history must never be silently deletable out from under it.
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->index('service_variant_id');
            $table->foreign('service_variant_id')
                ->references('id')->on('service_variants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropForeign(['service_variant_id']);
            $table->dropIndex(['service_variant_id']);
        });
    }
};
