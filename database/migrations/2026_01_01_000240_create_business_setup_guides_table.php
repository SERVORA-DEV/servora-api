<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business_setup_guides table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_setup_guides')) {
            return;
        }

        Schema::create('business_setup_guides', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_business_id');
            $table->json('done')->nullable();
            $table->json('skipped')->nullable();
            $table->boolean('finished')->default(false);
            $table->timestamps();
            $table->unique('spa_business_id', 'business_setup_guides_spa_business_id_unique');
            $table->foreign('spa_business_id', 'business_setup_guides_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_setup_guides');
    }
};
