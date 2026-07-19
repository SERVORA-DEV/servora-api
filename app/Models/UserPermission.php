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

        'admin_manage',
        'permission_manage',

        'subscription_plan_manage',
        'subscription_manage',

        'spa_business_manage',
        'spa_branch_manage',

        'staff_manage',
        'attendance_manage',

        'service_manage',
        'package_manage',
        'facility_manage',

        'client_manage',

        'appointment_manage',
        'queue_manage',

        'billing_manage',
        'payment_manage',
        'commission_manage',

        'loyalty_manage',
        'review_manage',

        'report_view',
        'report_export',

        'notification_manage',

        'audit_log_view',
    ];

    protected function casts(): array
    {
        return [
            'dashboard_view' => 'boolean',

            'admin_manage' => 'boolean',
            'permission_manage' => 'boolean',

            'subscription_plan_manage' => 'boolean',
            'subscription_manage' => 'boolean',

            'spa_business_manage' => 'boolean',
            'spa_branch_manage' => 'boolean',

            'staff_manage' => 'boolean',
            'attendance_manage' => 'boolean',

            'service_manage' => 'boolean',
            'package_manage' => 'boolean',
            'facility_manage' => 'boolean',

            'client_manage' => 'boolean',

            'appointment_manage' => 'boolean',
            'queue_manage' => 'boolean',

            'billing_manage' => 'boolean',
            'payment_manage' => 'boolean',
            'commission_manage' => 'boolean',

            'loyalty_manage' => 'boolean',
            'review_manage' => 'boolean',

            'report_view' => 'boolean',
            'report_export' => 'boolean',

            'notification_manage' => 'boolean',

            'audit_log_view' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}