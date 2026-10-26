<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A ticket's place in the line, apart from when the ticket was made. The line
    // used to be ordered by created_at, which can't change; skipping a client moves
    // them down one place by swapping this between two tickets, and the ticket
    // numbers themselves never move. Kept to the microsecond so two tickets made in
    // the same second still have an order. Backfilled from created_at; a ticket with
    // none is read as created_at (Queue::lineKey).
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->timestamp('queued_at', 6)->nullable()->after('queue_status');
            $table->index(['spa_branch_id', 'appointment_date', 'queued_at']);
        });

        DB::table('queues')->whereNull('queued_at')->update(['queued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex(['spa_branch_id', 'appointment_date', 'queued_at']);
            $table->dropColumn('queued_at');
        });
    }
};
