<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pausing a service in progress, and handing it to another therapist while it is paused
// (App\Support\Visits\ServicePause):
//  - appointment_services.paused_at: set while the service is paused (the clock is stopped);
//  - appointment_services.paused_minutes: how long it has been paused in all;
//  - therapist_assignments.assignment_status gets 'Handed Over': the therapist who stopped
//    and gave the client to someone else. Such a row earns nothing (only Completed rows do).
// Safe to run again.
return new class extends Migration
{
    private const BEFORE = ['Assigned', 'In Progress', 'Completed', 'Cancelled'];

    public function up(): void
    {
        if (! Schema::hasColumn('appointment_services', 'paused_at')) {
            Schema::table('appointment_services', function (Blueprint $table) {
                $table->timestamp('paused_at')->nullable();
                $table->integer('paused_minutes')->default(0);
            });
        }

        $this->statuses([...self::BEFORE, 'Handed Over']);
    }

    public function down(): void
    {
        // A handed-over row is a finished part of a service: kept as Completed, with nothing earned.
        DB::table('therapist_assignments')->where('assignment_status', 'Handed Over')
            ->update(['assignment_status' => 'Completed', 'commission_amount' => 0]);
        $this->statuses(self::BEFORE);

        if (Schema::hasColumn('appointment_services', 'paused_at')) {
            Schema::table('appointment_services', fn (Blueprint $table) => $table->dropColumn(['paused_at', 'paused_minutes']));
        }
    }

    // Laravel's enum() is a CHECK constraint on PostgreSQL. A database made by older migrations, or
    // moved here from another one, may have it under another name, or have the column as a native
    // enum type: every CHECK on the column is replaced with the new list, and a native type gets
    // the new value too, so 'Handed Over' can never be refused by what was there before.
    private function statuses(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $type = DB::selectOne("select data_type, udt_name from information_schema.columns where table_schema = current_schema() and table_name = 'therapist_assignments' and column_name = 'assignment_status'");
        if ($type && $type->data_type === 'USER-DEFINED') {
            foreach ($values as $v) {
                DB::statement('ALTER TYPE "'.str_replace('"', '""', $type->udt_name).'" ADD VALUE IF NOT EXISTS '."'".str_replace("'", "''", $v)."'");
            }
        }

        $checks = DB::select("select con.conname from pg_constraint con join pg_class rel on rel.oid = con.conrelid join pg_namespace ns on ns.oid = rel.relnamespace
            where rel.relname = 'therapist_assignments' and ns.nspname = current_schema() and con.contype = 'c' and pg_get_constraintdef(con.oid) ilike '%assignment_status%'");
        foreach ($checks as $check) {
            DB::statement('ALTER TABLE therapist_assignments DROP CONSTRAINT "'.str_replace('"', '""', $check->conname).'"');
        }

        $list = implode(', ', array_map(fn ($v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement("ALTER TABLE therapist_assignments ADD CONSTRAINT therapist_assignments_assignment_status_check CHECK (assignment_status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
