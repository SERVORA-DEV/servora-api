<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The package_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('package_services')) {
            return;
        }

        Schema::create('package_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('package_id');
            $table->integer('quantity')->default(1);
            $table->integer('sort_order')->default(1);
            $table->timestamps();
            $table->bigInteger('service_variant_id')->nullable();
            $table->bigInteger('service_id')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('home_duration_minutes')->nullable();
            $table->decimal('home_price', 10, 2)->nullable();
            $table->json('client_slots')->nullable();
            $table->unique(['package_id', 'service_variant_id'], 'package_services_package_id_service_variant_id_unique');
            $table->foreign('package_id', 'package_services_package_id_foreign')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('service_id', 'package_services_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('service_variant_id', 'package_services_service_variant_id_foreign')->references('id')->on('service_variants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_services');
    }
};
