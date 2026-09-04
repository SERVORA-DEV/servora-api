<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-user override slot for the new client_therapist_manage permission
    // (lets manager/front_officer set a client's preferred therapist without
    // manager needing full client_update) — added purely for schema/display
    // consistency with every other permission key. Same as every other key
    // here, nothing reads this column directly: EnsurePermission checks
    // config/permission.php only (see
    // 2026_08_25_090001_drop_appointment_confirm_from_user_permissions_table's
    // comment for the precedent).
    public function up(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->boolean('client_therapist_manage')->default(false)->after('client_delete');
        });
    }

    public function down(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropColumn('client_therapist_manage');
        });
    }
};
