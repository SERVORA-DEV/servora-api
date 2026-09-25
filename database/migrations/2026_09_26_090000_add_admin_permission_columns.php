<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// System administrator permissions become enforced (EnsurePermission now
// reads each admin's own user_permissions row, see AdminPermissions). The
// areas that had no key at all — service templates and platform settings —
// get columns here, and branch_view (a column already) joins the admin
// bundle.
//
// Every existing administrator is granted the new keys so nobody loses
// access to something they could do before this deploy; each admin's
// permissions can then be narrowed from Settings → Administrators.
return new class extends Migration
{
    private const NEW_COLUMNS = [
        'service_template_view',
        'service_template_create',
        'service_template_update',
        'service_template_delete',
        'setting_update',
    ];

    public function up(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            foreach (self::NEW_COLUMNS as $column) {
                $table->boolean($column)->default(false);
            }
        });

        $admins = DB::table('users')->where('role', 'system_administrator')->select('id');

        DB::table('user_permissions')
            ->whereIn('user_id', $admins)
            ->update(array_fill_keys([...self::NEW_COLUMNS, 'branch_view'], true));
    }

    public function down(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropColumn(self::NEW_COLUMNS);
        });
    }
};
