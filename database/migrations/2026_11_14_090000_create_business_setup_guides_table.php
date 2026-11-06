<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// The Management setup guide's progress, kept per business so a step done once
// stays done on every device, branch and admin login (BusinessSetupGuide).
// A new table only: nothing that exists today is changed. Until this has run,
// the guide keeps working from the browser alone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_setup_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->unique()->constrained('spa_businesses')->cascadeOnDelete();

            // Step ids seen done. Only ever added to.
            $table->json('done')->nullable();
            // Step ids put off for now.
            $table->json('skipped')->nullable();
            // The guide was completed or closed for good.
            $table->boolean('finished')->default(false);

            $table->timestamps();
        });

        Cache::forget('schema:business_setup_guides');
    }

    public function down(): void
    {
        Schema::dropIfExists('business_setup_guides');

        Cache::forget('schema:business_setup_guides');
    }
};
