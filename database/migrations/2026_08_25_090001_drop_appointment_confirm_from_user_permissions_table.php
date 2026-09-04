<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The client-confirmation step is gone from the appointment status
    // workflow (see 2026_08_25_090000_update_appointments_status_workflow),
    // and 'appointment_confirm' was already pruned from every role's
    // config/permission.php list — this column is the matching per-user
    // override slot, now dead (nothing reads it: EnsurePermission checks
    // config/permission.php only, not this table).
    public function up(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropColumn('appointment_confirm');
        });
    }

    public function down(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->boolean('appointment_confirm')->default(false)->after('appointment_update');
        });
    }
};
