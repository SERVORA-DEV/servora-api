<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The branch_service_facilities table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_service_facilities')) {
            return;
        }

        Schema::create('branch_service_facilities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('branch_service_id');
            $table->bigInteger('facility_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['branch_service_id', 'facility_id'], 'branch_service_facilities_branch_service_id_facility_id_unique');
            $table->foreign('branch_service_id', 'branch_service_facilities_branch_service_id_foreign')->references('id')->on('branch_services')->onDelete('cascade');
            $table->foreign('facility_id', 'branch_service_facilities_facility_id_foreign')->references('id')->on('facilities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_facilities');
    }
};
