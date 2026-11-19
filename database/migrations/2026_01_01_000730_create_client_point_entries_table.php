<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The client_point_entries table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_point_entries')) {
            return;
        }

        Schema::create('client_point_entries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('client_id');
            $table->bigInteger('billing_id')->nullable();
            $table->string('type', 10);
            $table->integer('points');
            $table->integer('remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('note')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'type'], 'client_point_entries_client_id_type_index');
            $table->foreign('billing_id', 'client_point_entries_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('client_id', 'client_point_entries_client_id_foreign')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by', 'client_point_entries_created_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        // Postgres only (a SQLite test database skips these).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX client_point_entries_bill_earn_unique ON client_point_entries (billing_id) WHERE (((type)::text = \'earn\'::text) AND (billing_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_point_entries');
    }
};
