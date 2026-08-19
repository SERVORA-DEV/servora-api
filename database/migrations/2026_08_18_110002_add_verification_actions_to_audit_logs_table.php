<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newActions = [
        'Create', 'Update', 'Delete',
        'Login', 'Logout',
        'Register', 'Verify Email', 'Reset Password', 'Change Password',
        'Approve', 'Reject', 'Suspend', 'Restore',
        'Assign', 'Unassign',
        'Check In', 'Check Out',
        'Complete', 'Cancel', 'Refund',
        'Redeem',
        'Export',
        'Activate', 'Deactivate',
        // New for owner identity / business verification uploads.
        'Upload', 'Submit', 'Replace',
    ];

    private array $oldActions = [
        'Create', 'Update', 'Delete',
        'Login', 'Logout',
        'Register', 'Verify Email', 'Reset Password', 'Change Password',
        'Approve', 'Reject', 'Suspend', 'Restore',
        'Assign', 'Unassign',
        'Check In', 'Check Out',
        'Complete', 'Cancel', 'Refund',
        'Redeem',
        'Export',
        'Activate', 'Deactivate',
    ];

    public function up(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->newActions));
        DB::statement("ALTER TABLE audit_logs MODIFY action ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->oldActions));
        DB::statement("ALTER TABLE audit_logs MODIFY action ENUM({$values}) NOT NULL");
    }
};
