<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_services')) {
            return;
        }

        Schema::create('staff_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('staff_id');
            $table->bigInteger('service_id');
            $table->timestamps();
            $table->unique(['staff_id', 'service_id'], 'staff_services_staff_id_service_id_unique');
            $table->foreign('service_id', 'staff_services_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('staff_id', 'staff_services_staff_id_foreign')->references('id')->on('staff')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_services');
    }
};
