<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The facility_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facility_services')) {
            return;
        }

        Schema::create('facility_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('facility_id');
            $table->bigInteger('service_id');
            $table->timestamps();
            $table->unique(['facility_id', 'service_id'], 'facility_services_facility_id_service_id_unique');
            $table->foreign('facility_id', 'facility_services_facility_id_foreign')->references('id')->on('facilities')->onDelete('cascade');
            $table->foreign('service_id', 'facility_services_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_services');
    }
};
