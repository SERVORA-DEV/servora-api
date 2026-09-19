<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Removes the client-confirmation step (Pending -> Confirmed) from the
    // appointment status workflow and gives "in service" a real stored
    // status instead of the derived checked_in_in_service label that used
    // to live in AppointmentEffectiveStatus. Widen -> backfill -> narrow so
    // existing rows survive the enum swap: the new CHECK constraint can only
    // go on once every row already holds one of the new labels.
    public function up(): void
    {
        // Drop the CHECK entirely for the backfill rather than widening it to
        // the union of both value sets: the old and new labels differ only by
        // case ('Completed' vs 'completed'), so an unconstrained varchar is
        // the clearer way to let them coexist for the few statements below.
        PostgresSchema::relaxEnum('appointments', 'status', default: 'scheduled', length: 20);

        DB::table('appointments')->whereIn('status', ['Pending', 'Confirmed'])->update(['status' => 'scheduled']);

        // A 'Checked In' row is only really "in service" if one of its
        // booked services has actually started — same signal
        // AppointmentEffectiveStatus used to derive checked_in_in_service
        // on the fly. Preserves that distinction as real stored data
        // instead of losing it in the collapse.
        DB::statement("
            UPDATE appointments a SET status = CASE WHEN EXISTS (
                SELECT 1 FROM appointment_services s WHERE s.appointment_id = a.id AND s.status = 'In Progress'
            ) THEN 'in_service' ELSE 'checked_in' END
            WHERE a.status = 'Checked In'
        ");

        DB::table('appointments')->where('status', 'Completed')->update(['status' => 'completed']);
        DB::table('appointments')->where('status', 'Cancelled')->update(['status' => 'cancelled']);
        DB::table('appointments')->where('status', 'No Show')->update(['status' => 'no_show']);

        PostgresSchema::redefineEnum('appointments', 'status', [
            'scheduled', 'checked_in', 'in_service', 'completed', 'cancelled', 'no_show',
        ], default: 'scheduled');
    }

    public function down(): void
    {
        PostgresSchema::relaxEnum('appointments', 'status', default: 'Pending', length: 20);

        DB::table('appointments')->where('status', 'scheduled')->update(['status' => 'Pending']);
        // in_service has no pre-existing counterpart — collapses back into
        // Checked In, the same bucket it was derived from before this
        // migration.
        DB::table('appointments')->whereIn('status', ['checked_in', 'in_service'])->update(['status' => 'Checked In']);
        DB::table('appointments')->where('status', 'completed')->update(['status' => 'Completed']);
        DB::table('appointments')->where('status', 'cancelled')->update(['status' => 'Cancelled']);
        DB::table('appointments')->where('status', 'no_show')->update(['status' => 'No Show']);

        PostgresSchema::redefineEnum('appointments', 'status', [
            'Pending', 'Confirmed', 'Checked In', 'Completed', 'Cancelled', 'No Show',
        ], default: 'Pending');
    }
};
