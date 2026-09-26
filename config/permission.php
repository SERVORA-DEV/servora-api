<?php

return [

    'system_administrator' => [
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
        'branch_approve',
        'branch_reject',
        'branch_suspend',

        'service_template_view',
        'service_template_create',
        'service_template_update',
        'service_template_delete',

        'setting_update',

        'report_view',
        'report_export',

        'notification_view',
        'notification_send',

        'audit_log_view',
    ],

    'business_owner' => [
        'dashboard_view',

        'branch_view',
        'branch_create',
        'branch_update',
        'branch_delete',

        'staff_view',
        'staff_create',
        'staff_update',
        'staff_delete',

        'attendance_view',
        'attendance_create',
        'attendance_update',
        'attendance_delete',

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

        'client_view',

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

        'commission_view',
        'commission_update',

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
    ],

    // Branch-level roles created via business account management (see
    // AccountService) — both are a fixed bundle applied at creation, not a
    // per-checkbox picker like system_administrator's, so these arrays
    // double as "everything this role gets" rather than just a whitelist.
    // Mirrors the role descriptions shown in the Add Staff modal on the
    // frontend (manager: runs one branch end to end, including its billing,
    // but not the shared catalog or the subscription; front
    // officer: queue/cashiering only, no reports or staff/service editing).
    'manager' => [
        'dashboard_view',

        'branch_view',

        'staff_view',
        'staff_create',
        'staff_update',
        'staff_delete',

        'attendance_view',
        'attendance_create',
        'attendance_update',
        'attendance_delete',

        // Catalog edits stay with the owner (the catalog is shared by every
        // branch); a manager sets their branch's availability and price.
        'service_view',

        'package_view',

        'facility_view',
        'facility_update',

        'client_view',
        'client_create',
        'client_update',
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

        'payment_view',
        'payment_create',

        'commission_view',
        'commission_update',

        'reward_view',
        'reward_update',

        'review_view',
        'review_hide',

        'report_view',
        'report_export',

        'notification_view',
    ],

    'front_officer' => [
        'dashboard_view',

        'attendance_view',
        'attendance_checkin',

        'queue_view',
        'queue_create',
        'queue_update',

        'client_view',
        'client_create',
        'client_update',
        'client_therapist_manage',

        'appointment_view',
        'appointment_create',
        'appointment_checkin',

        'billing_view',
        'billing_create',

        'payment_view',
        'payment_create',

        'notification_view',
    ],

];
