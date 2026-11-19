<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner_verification_reviews table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owner_verification_reviews')) {
            return;
        }

        Schema::create('owner_verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('spa_business_id');
            $table->string('part', 16);
            $table->string('evidence_hash', 64);
            $table->json('checks');
            $table->integer('revision')->default(1);
            $table->bigInteger('reviewed_by')->nullable();
            $table->string('decision', 32)->nullable();
            $table->timestamps();
            $table->unique(['spa_business_id', 'part'], 'owner_verification_reviews_spa_business_id_part_unique');
            $table->foreign('reviewed_by', 'owner_verification_reviews_reviewed_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_business_id', 'owner_verification_reviews_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_verification_reviews');
    }
};
