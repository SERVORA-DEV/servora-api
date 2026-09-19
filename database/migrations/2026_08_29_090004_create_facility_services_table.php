<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Business-wide Room <-> Service link ("this room can perform Swedish
    // Massage"), independent of branch/pricing — distinct from the unused,
    // branch-service-row-scoped branch_service_facilities table, which has
    // no model/repository/UI and is left alone.
    public function up(): void
    {
        Schema::create('facility_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_services');
    }
};
