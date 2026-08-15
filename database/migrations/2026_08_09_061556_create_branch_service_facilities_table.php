<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which room(s)/facility can host a given branch_service — e.g. "Hot
    // Stone Massage at the Davao branch can happen in Room 3 or the VIP
    // Suite". created_at only (no updated_at) — a pure link row is either
    // there or it isn't, never edited in place.
    public function up(): void
    {
        Schema::create('branch_service_facilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['branch_service_id', 'facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_facilities');
    }
};
