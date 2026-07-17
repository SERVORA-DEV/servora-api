<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_id')
                ->constrained()
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

            $table->unique('appointment_id');
            $table->index('queue_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};