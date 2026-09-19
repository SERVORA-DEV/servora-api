<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

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
        PostgresSchema::redefineEnum('audit_logs', 'action', $this->newActions);
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('audit_logs', 'action', $this->oldActions);
    }
};
