<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The spa_business_settings table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spa_business_settings')) {
            return;
        }

        Schema::create('spa_business_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_business_id');
            $table->json('payments')->nullable();
            $table->json('staff_policy')->nullable();
            $table->json('booking_defaults')->nullable();
            $table->json('notifications')->nullable();
            $table->timestamps();
            $table->json('role_permissions')->nullable();
            $table->json('programs')->nullable();
            $table->unique('spa_business_id', 'spa_business_settings_spa_business_id_unique');
            $table->foreign('spa_business_id', 'spa_business_settings_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spa_business_settings');
    }
};
