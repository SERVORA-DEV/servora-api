<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The package_addons table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('package_addons')) {
            return;
        }

        Schema::create('package_addons', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('package_id');
            $table->bigInteger('addon_id');
            $table->bigInteger('service_id');
            $table->timestamps();
            $table->unique(['package_id', 'addon_id', 'service_id'], 'package_addons_package_id_addon_id_service_id_unique');
            $table->foreign('addon_id', 'package_addons_addon_id_foreign')->references('id')->on('addons')->onDelete('cascade');
            $table->foreign('package_id', 'package_addons_package_id_foreign')->references('id')->on('packages')->onDelete('cascade');
            $table->foreign('service_id', 'package_addons_service_id_foreign')->references('id')->on('services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_addons');
    }
};
