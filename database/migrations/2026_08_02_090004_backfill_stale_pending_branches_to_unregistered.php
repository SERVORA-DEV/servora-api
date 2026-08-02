<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Before the registration flow existed, every new branch was blindly
    // stamped verification_status = 'Pending' on creation (see the old
    // SpaBranchService::createSpaBranch). Those rows never went through
    // submitRegistration — no pin, no lat/lng, never reviewed — so they
    // aren't really "pending review" at all. Only a genuine submission sets
    // latitude/longitude (or verified_by, if it was since approved/rejected),
    // so anything still 'Pending' with none of those set is one of the
    // stale pre-registration-flow rows, not an active request.
    public function up(): void
    {
        DB::table('spa_branches')
            ->where('verification_status', 'Pending')
            ->whereNull('latitude')
            ->whereNull('longitude')
            ->whereNull('verified_by')
            ->update(['verification_status' => 'Unregistered']);
    }

    // Not meaningfully reversible — there's no way to distinguish, after
    // the fact, which 'Unregistered' rows this touched from ones that were
    // always Unregistered.
    public function down(): void
    {
        //
    }
};
