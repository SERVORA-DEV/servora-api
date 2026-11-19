<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The queues table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queues')) {
            return;
        }

        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('appointment_id');
            $table->string('queue_number', 20);
            $table->enum('queue_status', ['Waiting', 'Called', 'Serving', 'Completed', 'Skipped', 'Cancelled'])->default('Waiting');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->bigInteger('spa_branch_id')->nullable();
            $table->date('appointment_date')->nullable();
            $table->timestamp('queued_at', 6)->nullable();
            $table->unique('appointment_id', 'queues_appointment_id_unique');
            $table->unique('uuid', 'queues_uuid_unique');
            $table->index('queue_status', 'queues_queue_status_index');
            $table->index(['spa_branch_id', 'appointment_date', 'queue_status'], 'queues_spa_branch_id_appointment_date_queue_status_index');
            $table->index(['spa_branch_id', 'appointment_date', 'queued_at'], 'queues_spa_branch_id_appointment_date_queued_at_index');
            $table->foreign('appointment_id', 'queues_appointment_id_foreign')->references('id')->on('appointments')->onDelete('cascade');
            $table->foreign('spa_branch_id', 'queues_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
