<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Prepares for EnsurePermission reading each account's own user_permissions
// row (until now it only checked config/permission.php, so these columns
// were display-only):
//
// - attendance_checkin is in front_officer's config bundle but never had a
//   column, so it could not be stored per account at all.
// - client_therapist_manage was added later with default false and no
//   backfill, so accounts created before it hold a stale false. It was never
//   enforced, so no owner could have meaningfully turned it off.
//
// Both are switched on for the roles whose bundle grants them, so enforcing
// per-account flags doesn't lock anyone out of what they can do today.
//
// Also adds spa_business_settings.role_permissions: the owner's default
// permission set per role (Business Settings → Staff Policies), applied when
// a new account is created.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->boolean('attendance_checkin')->default(false)->after('attendance_delete');
        });

        $this->grant('attendance_checkin', ['front_officer']);
        $this->grant('client_therapist_manage', ['manager', 'front_officer']);

        Schema::table('spa_business_settings', function (Blueprint $table) {
            $table->json('role_permissions')->nullable()->after('notifications');
        });
    }

    public function down(): void
    {
        Schema::table('spa_business_settings', function (Blueprint $table) {
            $table->dropColumn('role_permissions');
        });

        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropColumn('attendance_checkin');
        });
    }

    private function grant(string $column, array $roles): void
    {
        DB::table('user_permissions')
            ->whereIn('user_id', DB::table('users')->whereIn('role', $roles)->select('id'))
            ->update([$column => true]);
    }
};
