<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The owner's booking rules at the front desk (App\Support\FrontDeskPolicy,
// App\Service\Business\PolicyGate): a rule the desk breaks is refused unless
// someone allowed to override gives a reason — kept here on the booking.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // [{rule, message, reason, by, at}, …]
            $table->json('policy_overrides')->nullable()->after('remarks');
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            // How far this service has been extended, to hold against the
            // owner's "longest extension per service".
            $table->unsignedInteger('extended_minutes')->default(0)->after('extra_charge');
        });

        Schema::table('user_permissions', function (Blueprint $table) {
            // "Override booking rules" — a manager's by default; a front desk
            // account's only when the owner ticks it.
            $table->boolean('policy_override')->default(false);
        });

        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->where('role', 'manager')->select('id'))
            ->update(['policy_override' => true]);

        Cache::forget('schema:front_desk_policy');
    }

    public function down(): void
    {
        Schema::table('user_permissions', fn (Blueprint $table) => $table->dropColumn('policy_override'));
        Schema::table('appointment_services', fn (Blueprint $table) => $table->dropColumn('extended_minutes'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('policy_overrides'));

        Cache::forget('schema:front_desk_policy');
    }
};
