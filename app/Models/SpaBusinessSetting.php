<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaBusinessSetting extends Model
{
    public const SECTIONS = ['payments', 'staff_policy', 'booking_defaults', 'notifications'];

    // Roles whose starting permissions the owner sets in Staff Policies.
    public const ACCOUNT_ROLES = ['manager', 'front_officer'];

    // Returned for any key the owner has never saved. Mirrors the starting
    // values the web settings pages used before they were persisted, so an
    // existing business sees the same screen it always did.
    public const DEFAULTS = [
        'payments' => [
            'accept_cash' => true,
            'accept_gcash' => true,
            'accept_paymaya' => true,
            'accept_card' => false,
            'accept_xendit' => false,
            'accept_bank_transfer' => false,
            'gcash_number' => null,
            'paymaya_number' => null,
        ],

        'staff_policy' => [
            'commission_enabled' => true,
            'commission_type' => 'percentage',
            'default_commission_rate' => 15,
            'commission_applies_to' => 'service',
            'commission_release_cycle' => 'monthly',
            'commission_includes_extension' => true,
            'commission_includes_tips' => false,

            'attendance_tracking' => true,
            'require_check_in' => true,
            'allow_self_check_in' => true,
            'late_threshold_minutes' => 10,
            'allow_shift_swap' => false,
            'require_manager_approval' => true,
            'allow_overtime' => false,
        ],

        'booking_defaults' => [
            'online_booking_enabled' => true,
            'walk_in_enabled' => true,
            'home_service_enabled' => true,
            'allow_therapist_selection' => true,
            'auto_assign_therapist' => true,
            'allow_reschedule' => true,
            'allow_cancellation' => true,

            'booking_lead_time_minutes' => 30,
            'max_advance_booking_days' => 30,
            'max_services_per_booking' => 3,

            'cancellation_window_hours' => 24,
            'cancellation_policy_tier' => 'flexible',
            'no_show_policy' => 'warn',
            'no_show_suspend_after' => 3,

            'require_deposit' => false,
            'deposit_percent' => 30,

            'queue_enabled' => true,
            'auto_assign_queue' => true,
            'max_queue_size' => 20,
            'queue_number_format' => 'numeric',
            'estimated_wait_display' => true,
            'walk_ins_first_priority' => false,

            'allow_service_extension' => true,
            'extension_increment_mins' => 15,
            'max_extension_mins' => 60,
            'extension_requires_approval' => false,

            // Refund % per cancellation tier. The three tiers themselves are
            // fixed; only their refund share is the owner's to set.
            'cancellation_tiers' => [
                'flexible' => 100,
                'moderate' => 50,
                'strict' => 0,
            ],
        ],

        'notifications' => [
            'on_new_booking' => true,
            'on_cancellation' => true,
            'on_reschedule' => true,
            'on_client_no_show' => true,

            'send_client_reminder' => true,
            'reminder_timing' => 24,
            'send_staff_reminder' => true,
            'staff_reminder_timing' => 2,
            'send_payment_reminder' => true,
            'send_birthday_greeting' => true,

            'promotional_enabled' => false,
            'promotional_channel' => 'email',
            'subscription_renewal_alert' => true,
            'renewal_alert_days' => 7,
            'system_update_notif' => true,

            'email_channel' => true,
            'sms_channel' => false,
            'push_channel' => true,
            'in_app_channel' => true,
        ],
    ];

    protected $fillable = [
        'spa_business_id',
        'payments',
        'staff_policy',
        'booking_defaults',
        'notifications',
        'role_permissions',
    ];

    protected function casts(): array
    {
        return [
            'payments' => 'array',
            'staff_policy' => 'array',
            'booking_defaults' => 'array',
            'notifications' => 'array',
            'role_permissions' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    // The effective values for one section: stored keys layered over the
    // defaults (one level deep for nested maps like cancellation_tiers), and
    // limited to known keys so a retired setting never leaks back out.
    public function section(string $name): array
    {
        return self::mergeKnown(self::DEFAULTS[$name], $this->{$name} ?? []);
    }

    // The permissions a new account of $role starts with: every key in that
    // role's config/permission.php bundle, on unless the owner turned it off.
    // Keys outside the bundle can never be granted, whatever is stored.
    public function rolePermissions(string $role): array
    {
        $stored = $this->role_permissions[$role] ?? [];
        $result = [];

        foreach (config('permission.'.$role, []) as $key) {
            $result[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : true;
        }

        return $result;
    }

    private static function mergeKnown(array $defaults, array $stored): array
    {
        $result = [];

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored)) {
                $result[$key] = $default;
            } elseif (is_array($default) && is_array($stored[$key])) {
                $result[$key] = self::mergeKnown($default, $stored[$key]);
            } else {
                $result[$key] = $stored[$key];
            }
        }

        return $result;
    }
}
