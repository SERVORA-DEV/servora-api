<?php

use App\Support\LoyaltyEarning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Loyalty points are for clients who use the app (App\Support\LoyaltyEarning).
// From here on only an app user earns them; this takes off the points that
// clients WITHOUT the app were already holding.
//
// For each client with no app account (clients.user_id is null) and a
// balance: one history line saying so, the points they had earned marked as
// used up, and the balance set to 0. Their lifetime total (points ever
// earned) stays as history, and vouchers they bought with points are kept.
//
// No columns change. It CANNOT be undone: down() does nothing, because the
// points are gone once this has run.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('clients')
                ->whereNull('user_id')
                ->where('current_points', '>', 0)
                ->orderBy('id')
                ->get(['id', 'current_points'])
                ->each(function ($client) {
                    $take = LoyaltyEarning::toRemove(false, (int) $client->current_points);
                    if ($take <= 0) {
                        return;
                    }

                    DB::table('client_point_entries')->insert([
                        'client_id' => $client->id,
                        'type' => LoyaltyEarning::REMOVED_TYPE,
                        'points' => -$take,
                        'remaining' => 0,
                        'note' => LoyaltyEarning::REMOVED_NOTE,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // Nothing of what they earned is left to spend or expire.
                    DB::table('client_point_entries')
                        ->where('client_id', $client->id)
                        ->where('type', 'earn')
                        ->where('remaining', '>', 0)
                        ->update(['remaining' => 0, 'updated_at' => now()]);
                    DB::table('clients')->where('id', $client->id)->update(['current_points' => 0, 'updated_at' => now()]);
                });
        });
    }

    public function down(): void
    {
        // The points taken off are not kept anywhere to put back.
    }
};
