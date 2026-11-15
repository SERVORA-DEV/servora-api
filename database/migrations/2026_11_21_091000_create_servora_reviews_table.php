<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owners' reviews of SERVORA itself, shown on the public Reviews page once a
// system administrator publishes them. A new table only: nothing already
// stored changes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servora_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // One review per owner: writing again edits it.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('spa_business_id')->nullable()->constrained('spa_businesses')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 80)->nullable();
            $table->text('body');
            // Whether the business's name is shown with it (the owner chooses).
            $table->boolean('show_business')->default(true);
            // Pending, Published, Hidden.
            $table->string('status', 20)->default('Pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servora_reviews');
    }
};
