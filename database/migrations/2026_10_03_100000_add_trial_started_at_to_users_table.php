<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When this owner started their free trial. One trial per owner
            // account, ever — kept on the account itself so it still holds if
            // their business is removed and they register another.
            $table->timestamp('trial_started_at')->nullable()->after('terms_version');
        });

        // Owners who already took a trial before this column existed.
        DB::table('subscriptions')
            ->join('spa_businesses', 'spa_businesses.id', '=', 'subscriptions.spa_business_id')
            ->where('subscriptions.is_trial', true)
            ->whereNotNull('spa_businesses.owner_id')
            ->select('spa_businesses.owner_id', DB::raw('MIN(subscriptions.starts_at) as started_at'))
            ->groupBy('spa_businesses.owner_id')
            ->get()
            ->each(fn ($row) => DB::table('users')->where('id', $row->owner_id)->update(['trial_started_at' => $row->started_at ?? now()]));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_started_at');
        });
    }
};
