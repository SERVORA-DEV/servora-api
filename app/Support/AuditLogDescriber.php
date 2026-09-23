<?php

namespace App\Support;

use App\Models\AuditLog;

// Turns an audit_logs row into what Settings > Audit Logs shows: one plain
// sentence ("Approved branch registration", "Signed in via Authenticator
// app") and a category for the page's tabs:
//   account       — sign-ins and security changes on the admin's OWN account
//   moderation    — approve/reject/suspend/restore of businesses, branches,
//                   owner identity verifications
//   configuration — plans, billing settings, service templates, other
//                   administrator accounts, announcements
// The acted-on record's name ("Wellness Spa") is resolved separately, in bulk,
// by AuditLogService — this only reads the row itself.
class AuditLogDescriber
{
    private const LOGIN_METHODS = [
        'password' => 'Password',
        'authenticator' => 'Authenticator app',
        'recovery_code' => 'Recovery code',
        'email_code' => 'Email code',
    ];

    private const LOGOUT_REASONS = [
        'signed_out' => 'Signed out',
        'revoked' => 'Session revoked from another device',
        'password_changed' => 'Session ended — password changed',
        'password_reset' => 'Session ended — password reset',
    ];

    private const FAILURE_REASONS = [
        'wrong_password' => 'Failed sign-in — wrong password',
        'invalid_two_factor_code' => 'Failed sign-in — invalid two-factor code',
    ];

    private const MODERATION_TABLES = ['spa_businesses', 'spa_branches', 'owner_identity_verifications'];

    public static function category(AuditLog $log): string
    {
        if ($log->table_name === 'users' && $log->record_id !== null && $log->record_id === $log->user_id) {
            return 'account';
        }

        return in_array($log->table_name, self::MODERATION_TABLES, true) ? 'moderation' : 'configuration';
    }

    public static function summary(AuditLog $log): string
    {
        $new = $log->new_values ?? [];

        if (self::category($log) === 'account') {
            return self::accountSummary($log->action, $new);
        }

        return match ($log->table_name) {
            'spa_businesses' => match ($log->action) {
                'Approve' => 'Approved business verification',
                'Reject' => 'Rejected business verification',
                'Suspend' => 'Suspended business',
                'Restore' => 'Reactivated business',
                default => self::generic($log->action, 'business'),
            },
            'spa_branches' => match ($log->action) {
                'Approve' => 'Approved branch registration',
                'Reject' => 'Rejected branch registration',
                'Suspend' => 'Suspended branch',
                'Restore' => 'Reactivated branch',
                default => self::generic($log->action, 'branch'),
            },
            'owner_identity_verifications' => match ($log->action) {
                'Approve' => 'Approved owner identity',
                'Reject' => 'Rejected owner identity',
                default => self::generic($log->action, 'owner identity'),
            },
            'subscription_plans' => $log->action === 'Update' && ! empty($new['versioned'])
                ? 'Updated plan — new version created' . (isset($new['subscribers_notified'])
                    ? ", {$new['subscribers_notified']} " . ($new['subscribers_notified'] === 1 ? 'subscriber' : 'subscribers') . ' notified'
                    : '')
                : self::generic($log->action, 'subscription plan'),
            'system_settings' => 'Changed billing settings',
            'service_templates' => self::generic($log->action, 'service template'),
            'announcements' => 'Sent an announcement' . (isset($new['recipients']) ? " to {$new['recipients']} " . ($new['recipients'] === 1 ? 'administrator' : 'administrators') : ''),
            'users' => match ($log->action) {
                'Create' => 'Added administrator',
                'Update' => 'Updated administrator account',
                default => self::generic($log->action, 'administrator'),
            },
            default => self::generic($log->action, str_replace('_', ' ', $log->table_name)),
        };
    }

    private static function accountSummary(string $action, array $new): string
    {
        return match ($action) {
            'Login' => 'Signed in' . (isset(self::LOGIN_METHODS[$new['method'] ?? '']) ? ' via ' . self::LOGIN_METHODS[$new['method']] : ''),
            'Logout' => self::LOGOUT_REASONS[$new['reason'] ?? ''] ?? 'Signed out',
            'Login Failed' => self::FAILURE_REASONS[$new['reason'] ?? ''] ?? 'Failed sign-in',
            'Change Password' => 'Changed account password',
            'Activate' => 'Enabled two-factor authentication',
            'Deactivate' => 'Disabled two-factor authentication',
            'Verify Email' => 'Verified personal verification email',
            'Update' => match (true) {
                isset($new['recovery_codes']) => 'Regenerated two-factor recovery codes',
                array_key_exists('personal_email', $new) => 'Set personal verification email',
                default => 'Updated own account',
            },
            default => self::generic($action, 'own account'),
        };
    }

    private static function generic(string $action, string $object): string
    {
        $verb = match ($action) {
            'Create' => 'Created',
            'Update' => 'Updated',
            'Delete' => 'Deleted',
            'Restore' => 'Restored',
            'Approve' => 'Approved',
            'Reject' => 'Rejected',
            'Suspend' => 'Suspended',
            'Activate' => 'Activated',
            'Deactivate' => 'Deactivated',
            'Submit' => 'Submitted',
            'Upload' => 'Uploaded',
            default => $action,
        };

        return "{$verb} {$object}";
    }
}
