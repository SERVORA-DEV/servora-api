<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The branch_services table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_services')) {
            return;
        }

        Schema::create('branch_services', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_branch_id');
            $table->decimal('custom_price', 10, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->bigInteger('service_variant_id');
            $table->unique(['spa_branch_id', 'service_variant_id'], 'branch_services_spa_branch_id_service_variant_id_unique');
            $table->foreign('service_variant_id', 'branch_services_service_variant_id_foreign')->references('id')->on('service_variants')->onDelete('cascade');
            $table->foreign('spa_branch_id', 'branch_services_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_services');
    }
};
