<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Permissions that were never checked before are now (routes/api.php puts
// 'permission:<key>' on every business route), and a few keys were added to a
// role's bundle (config/permission.php) so a role that could already do the
// thing keeps being able to:
//
//   manager       attendance_checkin (timing staff in and out in Operation
//                 mode — the front-desk routes asked for it but the bundle
//                 didn't have it), facility_create, facility_delete,
//                 branch_update (hours, closures, listing, photos, booking
//                 rules, branch prices), audit_log_view
//   front_officer appointment_update, appointment_complete,
//                 appointment_cancel
//
// An account's own user_permissions row can only narrow its role's bundle, so
// the existing rows have to be switched on or these accounts would lose what
// they do today. The owner can then turn each one off per account
// (Staff Accounts → Access).
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['attendance_checkin', 'facility_create', 'facility_delete', 'branch_update', 'audit_log_view'],
        'front_officer' => ['appointment_update', 'appointment_complete', 'appointment_cancel'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => $columns) {
            DB::table('user_permissions')
                ->whereIn('user_id', DB::table('users')->where('role', $role)->select('id'))
                ->update(array_fill_keys($columns, true));
        }
    }

    // The columns stay; the switches go back to off for these roles.
    public function down(): void
    {
        foreach (self::GRANTS as $role => $columns) {
            DB::table('user_permissions')
                ->whereIn('user_id', DB::table('users')->where('role', $role)->select('id'))
                ->update(array_fill_keys($columns, false));
        }
    }
};
