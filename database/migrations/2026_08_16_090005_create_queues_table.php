<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Front-desk queue ticket for an appointment — one-to-one, hence the
    // unique appointment_id, since a booking only ever has one place in
    // line at a time.
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_id')
                ->unique()
                ->constrained('appointments')
                ->cascadeOnDelete();

            $table->string('queue_number', 20);

            $table->enum('queue_status', [
                'Waiting',
                'Called',
                'Serving',
                'Completed',
                'Skipped',
                'Cancelled',
            ])->default('Waiting');

            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('queue_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
