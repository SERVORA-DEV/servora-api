<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The reviews table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reviews')) {
            return;
        }

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('appointment_id');
            $table->bigInteger('client_id')->nullable();
            $table->smallInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->enum('status', ['Published', 'Hidden'])->default('Published');
            $table->timestamp('reviewed_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('appointment_id', 'reviews_appointment_id_unique');
            $table->unique('uuid', 'reviews_uuid_unique');
            $table->index('rating', 'reviews_rating_index');
            $table->index('status', 'reviews_status_index');
            $table->foreign('appointment_id', 'reviews_appointment_id_foreign')->references('id')->on('appointments')->onDelete('cascade');
            $table->foreign('client_id', 'reviews_client_id_foreign')->references('id')->on('clients')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
