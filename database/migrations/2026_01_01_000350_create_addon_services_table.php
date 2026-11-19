<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The addon_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addon_services')) {
            return;
        }

        Schema::create('addon_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('addon_id');
            $table->bigInteger('service_id');
            $table->timestamps();
            $table->unique(['addon_id', 'service_id'], 'addon_services_addon_id_service_id_unique');
            $table->foreign('addon_id', 'addon_services_addon_id_foreign')->references('id')->on('addons')->onDelete('cascade');
            $table->foreign('service_id', 'addon_services_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_services');
    }
};
