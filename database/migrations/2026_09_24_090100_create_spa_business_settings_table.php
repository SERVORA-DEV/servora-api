<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per business holding the owner's Business Settings configuration.
// Each settings page owns one JSON column — the pages save independently and
// their shapes change often, so a column per toggle would mean a migration
// for every new switch. Defaults live in SpaBusinessSetting::DEFAULTS, merged
// in on read, so a missing row or key always resolves to a sane value.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spa_business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->unique()->constrained('spa_businesses')->cascadeOnDelete();

            $table->json('payments')->nullable();
            $table->json('staff_policy')->nullable();
            $table->json('booking_defaults')->nullable();
            $table->json('notifications')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_business_settings');
    }
};
