<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The servora_reviews table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('servora_reviews')) {
            return;
        }

        Schema::create('servora_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('user_id');
            $table->bigInteger('spa_business_id')->nullable();
            $table->smallInteger('rating');
            $table->string('title', 80)->nullable();
            $table->text('body');
            $table->boolean('show_business')->default(true);
            $table->string('status', 20)->default('Pending');
            $table->bigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->smallInteger('pending_rating')->nullable();
            $table->string('pending_title', 80)->nullable();
            $table->text('pending_body')->nullable();
            $table->boolean('pending_show_business')->nullable();
            $table->timestamp('pending_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->unique('user_id', 'servora_reviews_user_id_unique');
            $table->unique('uuid', 'servora_reviews_uuid_unique');
            $table->index(['status', 'published_at'], 'servora_reviews_status_published_at_index');
            $table->foreign('reviewed_by', 'servora_reviews_reviewed_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('spa_business_id', 'servora_reviews_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('set null');
            $table->foreign('user_id', 'servora_reviews_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servora_reviews');
    }
};
