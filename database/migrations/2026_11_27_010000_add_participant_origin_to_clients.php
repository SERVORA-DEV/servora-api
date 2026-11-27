<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('created_as_participant')->default(false);
        });

        if (! Schema::hasColumns('appointment_packages', ['client_slot', 'shared_from_id'])) {
            return;
        }

        // The app creates a new companion profile between the payer's appointment
        // and the companion's first appointment, in the same booking transaction.
        $historicalParticipants = DB::table('appointment_packages as place')
            ->join('appointments as guest', 'guest.id', '=', 'place.appointment_id')
            ->join('appointment_packages as purchase', 'purchase.id', '=', 'place.shared_from_id')
            ->join('appointments as payer', 'payer.id', '=', 'purchase.appointment_id')
            ->join('clients as profile', 'profile.id', '=', 'guest.client_id')
            ->select('profile.id')
            ->where('place.client_slot', '>', 1)
            ->where('guest.source', 'Mobile')
            ->where('payer.source', 'Mobile')
            ->whereColumn('payer.client_id', '<>', 'guest.client_id')
            ->whereColumn('profile.created_at', '>=', 'payer.created_at')
            ->whereColumn('profile.created_at', '<=', 'guest.created_at')
            ->whereNotExists(fn (Builder $prior) => $prior->selectRaw('1')->from('appointments as earlier')
                ->whereColumn('earlier.client_id', 'profile.id')->whereColumn('earlier.id', '<', 'guest.id'))
            ->whereNotExists(fn (Builder $audit) => $audit->selectRaw('1')->from('audit_logs')
                ->where('table_name', 'clients')->where('action', 'Create')->whereColumn('record_id', 'profile.id'));

        DB::table('clients')->whereIn('id', $historicalParticipants)->update(['created_as_participant' => true]);
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('created_as_participant'));
    }
};
