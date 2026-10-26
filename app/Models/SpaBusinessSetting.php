<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaBusinessSetting extends Model
{
    public const SECTIONS = ['payments', 'staff_policy', 'booking_defaults', 'notifications', 'programs'];

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
            'accept_bank_transfer' => false,
            'gcash_number' => null,
            'paymaya_number' => null,
            // Business Defaults → Refunds: a refund of a paid bill that fits
            // none of the owner's refund policies (App\Support\RefundPolicyRule).
            'allow_custom_refunds' => true,
        ],

        'staff_policy' => [
            'commission_enabled' => true,
            'commission_type' => 'percentage',
            'default_commission_rate' => 15,
            'commission_applies_to' => 'service',
            'commission_release_cycle' => 'monthly',
            'commission_includes_extension' => true,
            'commission_includes_tips' => false,
            // A service done on a home visit earns this instead of the staff
            // member's plan rate (App\Support\CommissionShare); the home
            // service fee still goes to the therapist. Off unless the owner asks.
            'home_commission_enabled' => false,
            'home_commission_type' => 'percentage',
            'home_commission_rate' => 0,

            'attendance_tracking' => true,
            'require_check_in' => true,
            'late_threshold_minutes' => 10,
            'allow_shift_swap' => false,
            // A manager's change to a staff schedule waits for the owner
            // (StaffScheduleService). Off unless the owner asks for it.
            'require_manager_approval' => false,
            // Count time worked past the shift end, reported as overtime
            // (AttendanceStatusCalculator). Off: hours stop at the shift end.
            'allow_overtime' => false,

            // What lateness and absences take off a staff member's earnings
            // for the pay period (App\Support\AttendancePenalty): a fixed
            // amount per late day and per absent day, never below zero. Each
            // change applies from that day on (StaffPenaltyRate). Off unless
            // the owner asks.
            'late_penalty_enabled' => false,
            // 'day': the amount for each late day; 'hour': the amount per
            // hour late, for the minutes they were late.
            'late_penalty_mode' => 'day',
            'late_penalty_amount' => 0,
            'absent_penalty_enabled' => false,
            'absent_penalty_amount' => 0,
        ],

        'booking_defaults' => [
            // Home service (HomeServiceRules): off until the owner turns it
            // on; a flat fee in pesos that goes to the therapist; how far
            // from the branch, in km, a client's address may be; and the
            // least service time worth the trip, in minutes (0 = no minimum).
            'home_service_enabled' => false,
            'home_service_fee' => 0,
            'home_service_radius_km' => 10,
            'home_service_min_minutes' => 0,
            'allow_reschedule' => true,
            // How many times a client may move one booking in the app
            // (0 = none); the front desk isn't held to it.
            'max_reschedules_per_booking' => 2,
            'allow_cancellation' => true,

            'max_advance_booking_days' => 30,
            'max_services_per_booking' => 3,
            // Bookings one client may have on the same day (client app).
            'max_bookings_per_client_per_day' => 1,
            // Reservations accepted in one clock hour (a booking at 8:34 PM is in
            // the 8 PM hour). 0 = no limit. See App\Support\HourlyCapacity.
            'max_bookings_per_hour' => 0,
            // How many hours ahead a booking has to be made (0 = any time from now on).
            // The app is stopped by it; the front desk needs a reason to book sooner.
            'booking_lead_time_hours' => 1,
            // The most service time one booking may hold, in minutes
            // (0 = no limit). The client app is refused past it; the front
            // desk is only warned — see BookingPolicy::serviceTimeProblem.
            'max_service_minutes_per_booking' => 0,
            // A package, or one long service, may run past that limit; the limit
            // then only stops more services being added. Off: nothing may.
            'allow_booking_overlap' => false,

            'cancellation_window_hours' => 24,
            // What a missed booking does (Business Defaults → Missed bookings):
            // nothing, a warning, or — after no_show_suspend_after missed since
            // the client's last visit — booking in the app paused for them. The
            // owner chooses; nobody is flagged unless the business asks for it.
            'no_show_policy' => 'warn',
            'no_show_suspend_after' => 3,
            // How long after its booked time a reservation is still held
            // (App\Support\ReservationHold): its staff can't be started on
            // anyone else, and arriving in time keeps the booked place in the
            // line. Arriving later puts the client at the back.
            'reservation_grace_minutes' => 15,


            'queue_enabled' => true,
            'auto_assign_queue' => true,
            'max_queue_size' => 20,
            'queue_number_format' => 'numeric',
            'walk_ins_first_priority' => false,

            'allow_service_extension' => true,
            'extension_increment_mins' => 15,
            'max_extension_mins' => 60,
            'extension_requires_approval' => false,

        ],

        'notifications' => [
            'on_new_booking' => true,
            'on_cancellation' => true,
            'on_reschedule' => true,
            'on_client_no_show' => true,
            // The desk is told when a service in progress has run its time.
            'on_service_end' => true,

            // "When a manager changes something" — the owner hears about a
            // kind of change only after switching it on (ManagerChangeNotifier).
            'on_manager_offerings' => false,
            'on_manager_hours' => false,
            'on_manager_booking_rules' => false,
            'on_manager_rooms' => false,
            'on_manager_staff' => false,
            'on_manager_listing' => false,

            // "Managers are always told about" — the kinds of the owner's own
            // changes a manager cannot switch off in their notification
            // settings (ManagerNotificationPrefs). The rest is each manager's
            // choice.
            'lock_owner_hours' => true,
            'lock_owner_booking_rules' => true,
            'lock_owner_catalog' => true,
            'lock_owner_offerings' => false,
            'lock_owner_staff' => false,
            'lock_owner_rooms' => false,
            'lock_owner_listing' => false,

            // "Front desk is told about" — the kinds of change at a branch
            // (the owner's or a manager's) its front desk accounts hear
            // about. The owner's choice alone (ManagerChangeNotifier).
            'frontdesk_hours' => true,
            'frontdesk_booking_rules' => true,
            'frontdesk_offerings' => true,
            'frontdesk_staff' => true,
            'frontdesk_rooms' => true,
            'frontdesk_listing' => true,

            'send_client_reminder' => true,
            'reminder_timing' => 24,
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
        // Customer Programs module switch — not every spa runs loyalty,
        // vouchers, discounts or memberships, so it starts off. The programs
        // themselves live in customer_programs.
        'programs' => [
            'enabled' => false,
        ],
    ];

    protected $fillable = [
        'spa_business_id',
        'payments',
        'staff_policy',
        'booking_defaults',
        'notifications',
        'programs',
        'role_permissions',
    ];

    protected function casts(): array
    {
        return [
            'payments' => 'array',
            'staff_policy' => 'array',
            'booking_defaults' => 'array',
            'notifications' => 'array',
            'programs' => 'array',
            'role_permissions' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    // The effective values for one section: stored keys layered over the
    // defaults (one level deep for nested maps), and
    // limited to known keys so a retired setting never leaks back out.
    public function section(string $name): array
    {
        return self::mergeKnown(self::DEFAULTS[$name], $this->{$name} ?? []);
    }

    // The permissions a new account of $role starts with: every key in that
    // role's config/permission.php bundle, on unless the owner turned it off
    // (or it's in permission.default_off and the owner never turned it on).
    // Keys outside the bundle can never be granted, whatever is stored.
    public function rolePermissions(string $role): array
    {
        $stored = $this->role_permissions[$role] ?? [];
        $offByDefault = config('permission.default_off.'.$role, []);
        $result = [];

        foreach (config('permission.'.$role, []) as $key) {
            $result[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : ! in_array($key, $offByDefault, true);
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
