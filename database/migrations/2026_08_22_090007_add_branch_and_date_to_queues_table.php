<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Denormalized (same pattern as billings/payments already carrying
    // spa_business_id/spa_branch_id) so "today's queue for my branch" never
    // needs a join through appointments. Backfilled by AppointmentService
    // at insert time (addToQueue()), not by a data migration — the table
    // has zero real consumers today.
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->foreignId('spa_branch_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('spa_branches')
                ->cascadeOnDelete();

            $table->date('appointment_date')->nullable()->after('spa_branch_id');

            $table->index(['spa_branch_id', 'appointment_date', 'queue_status']);
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex(['spa_branch_id', 'appointment_date', 'queue_status']);
            $table->dropForeign(['spa_branch_id']);
            $table->dropColumn(['spa_branch_id', 'appointment_date']);
        });
    }
};
