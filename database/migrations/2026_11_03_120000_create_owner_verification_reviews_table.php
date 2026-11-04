<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->string('part', 16);
            $table->string('evidence_hash', 64);
            $table->json('checks');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32)->nullable();
            $table->timestamps();
            $table->unique(['spa_business_id', 'part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_verification_reviews');
    }
};
