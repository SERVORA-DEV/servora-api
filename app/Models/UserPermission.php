<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',

        'dashboard_view',

        'admin_view',
        'admin_create',
        'admin_update',
        'admin_delete',

        'permission_update',

        'subscription_plan_view',
        'subscription_plan_create',
        'subscription_plan_update',
        'subscription_plan_delete',

        'spa_business_view',
        'spa_business_create',
        'spa_business_update',
        'spa_business_approve',
        'spa_business_reject',
        'spa_business_suspend',

        'branch_view',
        'branch_create',
        'branch_update',
        'branch_delete',
        'branch_approve',
        'branch_reject',
        'branch_suspend',

        'service_view',
        'service_create',
        'service_update',
        'service_delete',

        'package_view',
        'package_create',
        'package_update',
        'package_delete',

        'facility_view',
        'facility_create',
        'facility_update',
        'facility_delete',

        'staff_view',
        'staff_create',
        'staff_update',
        'staff_delete',

        'attendance_view',
        'attendance_create',
        'attendance_update',
        'attendance_delete',

        'commission_view',
        'commission_update',

        'client_view',
        'client_create',
        'client_update',
        'client_delete',
        'client_therapist_manage',

        'appointment_view',
        'appointment_create',
        'appointment_update',
        'appointment_checkin',
        'appointment_complete',
        'appointment_cancel',

        'queue_view',
        'queue_create',
        'queue_update',

        'billing_view',
        'billing_create',
        'billing_update',
        'billing_delete',

        'payment_view',
        'payment_create',
        'payment_refund',

        'reward_view',
        'reward_create',
        'reward_update',
        'reward_delete',

        'review_view',
        'review_hide',
        'review_delete',

        'report_view',
        'report_export',

        'notification_view',
        'notification_send',

        'audit_log_view',
    ];

    protected function casts(): array
    {
        return [
            'dashboard_view' => 'boolean',

            'admin_view' => 'boolean',
            'admin_create' => 'boolean',
            'admin_update' => 'boolean',
            'admin_delete' => 'boolean',

            'permission_update' => 'boolean',

            'subscription_plan_view' => 'boolean',
            'subscription_plan_create' => 'boolean',
            'subscription_plan_update' => 'boolean',
            'subscription_plan_delete' => 'boolean',

            'spa_business_view' => 'boolean',
            'spa_business_create' => 'boolean',
            'spa_business_update' => 'boolean',
            'spa_business_approve' => 'boolean',
            'spa_business_reject' => 'boolean',
            'spa_business_suspend' => 'boolean',

            'branch_view' => 'boolean',
            'branch_create' => 'boolean',
            'branch_update' => 'boolean',
            'branch_delete' => 'boolean',
            'branch_approve' => 'boolean',
            'branch_reject' => 'boolean',
            'branch_suspend' => 'boolean',

            'service_view' => 'boolean',
            'service_create' => 'boolean',
            'service_update' => 'boolean',
            'service_delete' => 'boolean',

            'package_view' => 'boolean',
            'package_create' => 'boolean',
            'package_update' => 'boolean',
            'package_delete' => 'boolean',

            'facility_view' => 'boolean',
            'facility_create' => 'boolean',
            'facility_update' => 'boolean',
            'facility_delete' => 'boolean',

            'staff_view' => 'boolean',
            'staff_create' => 'boolean',
            'staff_update' => 'boolean',
            'staff_delete' => 'boolean',

            'attendance_view' => 'boolean',
            'attendance_create' => 'boolean',
            'attendance_update' => 'boolean',
            'attendance_delete' => 'boolean',

            'commission_view' => 'boolean',
            'commission_update' => 'boolean',

            'client_view' => 'boolean',
            'client_create' => 'boolean',
            'client_update' => 'boolean',
            'client_delete' => 'boolean',
            'client_therapist_manage' => 'boolean',

            'appointment_view' => 'boolean',
            'appointment_create' => 'boolean',
            'appointment_update' => 'boolean',
            'appointment_checkin' => 'boolean',
            'appointment_complete' => 'boolean',
            'appointment_cancel' => 'boolean',

            'queue_view' => 'boolean',
            'queue_create' => 'boolean',
            'queue_update' => 'boolean',

            'billing_view' => 'boolean',
            'billing_create' => 'boolean',
            'billing_update' => 'boolean',
            'billing_delete' => 'boolean',

            'payment_view' => 'boolean',
            'payment_create' => 'boolean',
            'payment_refund' => 'boolean',

            'reward_view' => 'boolean',
            'reward_create' => 'boolean',
            'reward_update' => 'boolean',
            'reward_delete' => 'boolean',

            'review_view' => 'boolean',
            'review_hide' => 'boolean',
            'review_delete' => 'boolean',

            'report_view' => 'boolean',
            'report_export' => 'boolean',

            'notification_view' => 'boolean',
            'notification_send' => 'boolean',

            'audit_log_view' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
