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
        'Upload', 'Submit', 'Replace',
        // Wrong password / wrong two-factor code — Settings → Login History.
        'Login Failed',
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
        'Upload', 'Submit', 'Replace',
    ];

    public function up(): void
    {
        PostgresSchema::redefineEnum('audit_logs', 'action', $this->newActions);
    }

    public function down(): void
    {
        // Rows using the removed value would violate the old constraint.
        \Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'Login Failed')->delete();

        PostgresSchema::redefineEnum('audit_logs', 'action', $this->oldActions);
    }
};
