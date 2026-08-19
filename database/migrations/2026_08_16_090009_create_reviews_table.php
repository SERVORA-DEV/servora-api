<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A client's rating/feedback on a completed appointment — one review
    // per appointment, hence the unique appointment_id. client_id is kept
    // nullable and set-null-on-delete (rather than cascading, like
    // audit_logs.user_id) so the review survives even if the client record
    // is later removed.
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_id')
                ->unique()
                ->constrained('appointments')
                ->cascadeOnDelete();

            $table->foreignId('client_id')
                ->nullable()
                ->constrained('clients')
                ->nullOnDelete();

            $table->unsignedTinyInteger('rating');

            $table->text('comment')->nullable();

            $table->boolean('is_anonymous')->default(false);

            $table->enum('status', ['Published', 'Hidden'])->default('Published');

            $table->timestamp('reviewed_at')->useCurrent();

            $table->timestamps();
            $table->softDeletes();

            $table->index('rating');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
