<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Business\AccountController;
use App\Http\Controllers\Business\AddonController;
use App\Http\Controllers\Business\AppointmentController;
use App\Http\Controllers\Business\AppointmentServiceController;
use App\Http\Controllers\Business\AttendanceController;
use App\Http\Controllers\Business\BillingAdjustmentController;
use App\Http\Controllers\Business\BillingController;
use App\Http\Controllers\Business\BranchClosureController;
use App\Http\Controllers\Business\BranchOfferingController;
use App\Http\Controllers\Business\BranchScheduleController;
use App\Http\Controllers\Business\BranchSettingsController;
use App\Http\Controllers\Business\BusinessAuditLogController;
use App\Http\Controllers\Business\BusinessSettingsController;
use App\Http\Controllers\Business\CatalogRequestController;
use App\Http\Controllers\Business\ClientController;
use App\Http\Controllers\Business\CommissionPlanController;
use App\Http\Controllers\Business\CustomerProgramController;
use App\Http\Controllers\Business\DashboardController;
use App\Http\Controllers\Business\FacilityController;
use App\Http\Controllers\Business\FrontOfficeAttendanceController;
use App\Http\Controllers\Business\FrontOfficeDashboardController;
use App\Http\Controllers\Business\FrontOfficeLookupController;
use App\Http\Controllers\Business\LivePulseController;
use App\Http\Controllers\Business\ManagerNotificationPrefsController;
use App\Http\Controllers\Business\NotificationController as BusinessNotificationController;
use App\Http\Controllers\Business\OwnerVerificationController as BusinessOwnerVerificationController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\PaymentController;
use App\Http\Controllers\Business\PlanAccessController;
use App\Http\Controllers\Business\ProgramRequestController;
use App\Http\Controllers\Business\QueueController;
use App\Http\Controllers\Business\RefundPolicyController;
use App\Http\Controllers\Business\ServiceController;
use App\Http\Controllers\Business\SpaBranchController;
use App\Http\Controllers\Business\SpaBusinessController;
use App\Http\Controllers\Business\StaffController;
use App\Http\Controllers\Business\StaffEarningsController;
use App\Http\Controllers\Business\StaffScheduleController;
use App\Http\Controllers\Business\StaffTransferController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Client\ClientAccountController;
use App\Http\Controllers\Client\ClientAppointmentController;
use App\Http\Controllers\Client\ClientBookingController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\ClientRewardsController;
use App\Http\Controllers\ContactEmailController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MobileReleaseController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\System\AdminUsersController;
use App\Http\Controllers\System\AnnouncementController;
use App\Http\Controllers\System\AuditLogController;
use App\Http\Controllers\System\BranchController;
use App\Http\Controllers\System\BranchRegistrationController;
use App\Http\Controllers\System\BusinessController;
use App\Http\Controllers\System\DashboardController as SystemDashboardController;
use App\Http\Controllers\System\NotificationController;
use App\Http\Controllers\System\OwnerVerificationController as SystemOwnerVerificationController;
use App\Http\Controllers\System\SecurityController;
use App\Http\Controllers\System\ServiceTemplateController;
use App\Http\Controllers\System\SubscriberController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\System\SystemSettingController;
use App\Http\Controllers\System\TransactionController;
use App\Http\Controllers\XenditWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Terms of Service + Privacy Policy, shown by the web and mobile apps.
Route::get('/legal', [LegalController::class, 'show']);

// authentication part
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
// The administrators' door: the only place an administrator password is
// accepted (the owner door above refuses it). Its own, tighter rate limit, and
// the optional ADMIN_ALLOWED_IPS list — see config/admin_access.php.
Route::post('/auth/admin/login', [AuthController::class, 'adminLogin'])->middleware(['admin.ip', 'throttle:admin-login']);
// "Continue with Google" for business owners (sign-in and sign-up) — see
// UserService::loginWithGoogle. Per-IP, since there is no email input to key on.
Route::post('/auth/google', [AuthController::class, 'loginWithGoogle'])->middleware('throttle:20,1');
// Per-IP throttles on top of UserService's per-challenge attempt cap.
Route::post('/auth/two-factor/verify', [AuthController::class, 'verifyTwoFactorLogin'])->middleware('throttle:10,1');
Route::post('/auth/two-factor/request-email-code', [AuthController::class, 'requestTwoFactorEmailCode'])->middleware('throttle:5,1');
Route::post('/auth/register', [RegisterController::class, 'registerClient'])->middleware('throttle:auth-sensitive');
Route::post('/auth/forget-password', [AuthController::class, 'forgetPassword'])->middleware('throttle:auth-sensitive');
Route::post('/auth/forget-password/verify-otp', [AuthController::class, 'verifyForgetPasswordOtp'])->middleware('throttle:auth-otp');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-otp');
Route::post('/auth/resend-verification', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:6,1');
// Polled by the sign-up page so it updates the moment the emailed link is
// clicked — see UserService::verificationStatus.
Route::post('/auth/verification-status', [AuthController::class, 'verificationStatus'])
    ->middleware('throttle:60,1');
Route::post('/auth/verify-registration-otp', [AuthController::class, 'verifyRegistrationOtp'])
    ->middleware('throttle:10,1');
Route::post('/auth/resend-registration-otp', [AuthController::class, 'resendRegistrationOtp'])
    ->middleware('throttle:6,1');
Route::post('/business/administrator/register', [RegisterController::class, 'register'])->middleware('throttle:auth-sensitive');

// display available subscription plan
Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);

// Public, unauthenticated business lookup — backs the branded
// /login/{business_uuid} page on the frontend (see PublicSpaBusinessResource
// for what's exposed here).
Route::get('/business/{uuid}/public', [SpaBusinessController::class, 'publicShow']);

// Public: the newest mobile app version and the oldest still accepted, so an installed app can
// offer (or require) the update. Published with `php artisan mobile:release`.
Route::get('/app/release', [MobileReleaseController::class, 'show'])->middleware('throttle:60,1');

// Public, unauthenticated "spas near me" lookup for the client mobile app's
// Home screen — see NearbySpaResource for what's exposed here.
Route::get('/spas/nearby', [SpaBranchController::class, 'nearby']);

// Public, unauthenticated branch detail lookup — must stay registered
// AFTER /spas/nearby above, otherwise this {uuid} wildcard would shadow it.
// See BranchDetailResource for what's exposed here.
Route::get('/spas/{uuid}', [SpaBranchController::class, 'publicShow']);

// Public, unauthenticated therapist-availability lookup for the client
// mobile booking flow: which of this branch's therapists can take a
// booking at a given date/time/duration. Separate from /spas/{uuid}
// because that endpoint has no requested window to judge them against.
// The literal '/therapists' segment keeps this out of the {uuid} wildcard
// above's way either order, but it's kept after it for consistency.
Route::get('/spas/{uuid}/therapists', [SpaBranchController::class, 'publicTherapistAvailability']);

// Public: when each therapist who can do the visit is next free, and that
// day's free stretches — the booking flow's staff step, which comes before
// the date (SpaBranchService::publicTherapistFreeTimes).
Route::get('/spas/{uuid}/therapists/free-times', [SpaBranchController::class, 'publicTherapistFreeTimes']);

// Public: the start times a client can actually book on one day (hours,
// break, service end before closing, booking policy, therapists on shift
// vs bookings already made) — see BookingSlotService.
Route::get('/spas/{uuid}/slots', [SpaBranchController::class, 'publicSlots']);

// Public, unauthenticated: the dates a branch therapist is off within a
// window, so the client booking calendar can grey them out once a therapist
// has been picked. Dates only — never shift times.
Route::get('/spas/{uuid}/therapists/{staffUuid}/days-off', [SpaBranchController::class, 'publicTherapistDaysOff']);
// Public, unauthenticated: a branch's scanner. A member of staff opens the
// link the front desk gave them and scans a client's QR to start a home
// visit — the secret in the link says which branch, and only that branch's
// bookings can be started through it. Throttled against guessing codes.
Route::get('/scanner/{token}', [ScannerController::class, 'show'])->middleware('throttle:60,1');
Route::post('/scanner/{token}/start', [ScannerController::class, 'start'])->middleware('throttle:20,1');

// Published reviews for a branch's page — first name + last initial only.
Route::get('/spas/{uuid}/reviews', [SpaBranchController::class, 'publicReviews'])->whereUuid('uuid');

// Private verification documents (government ID front/back, face-scan
// frames, business registration document) are served directly by
// Cloudinary now — see DocumentUploadService::signedUrl() — so there is no
// local retrieval route for them anymore.

// auth sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'getUser']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);

    // Pop-ups for new notifications while Servora isn't open (any role).
    Route::get('/push/config', [PushSubscriptionController::class, 'config']);
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy']);

    // mobile client (end customer) routes
    Route::prefix('client')
        ->middleware('role:client')
        ->group(function () {
            Route::patch('profile', [ClientProfileController::class, 'update']);
            // The client's own picture (shown to the spas they book with).
            Route::post('profile/photo', [ClientProfileController::class, 'setPhoto'])->middleware('throttle:20,1');
            Route::delete('profile/photo', [ClientProfileController::class, 'removePhoto']);
            Route::post('change-password', [ClientAccountController::class, 'changePassword']);
            // Password-confirmed; anonymizes the login (see ClientAccountService).
            Route::delete('account', [ClientAccountController::class, 'deleteAccount'])->middleware('throttle:5,1');

            Route::post('appointments', [ClientAppointmentController::class, 'store']);
            Route::get('appointments', [ClientBookingController::class, 'index']);
            Route::get('appointments/{uuid}', [ClientBookingController::class, 'show'])->whereUuid('uuid');
            Route::post('appointments/{uuid}/cancel', [ClientBookingController::class, 'cancel'])->whereUuid('uuid');
            Route::patch('appointments/{uuid}/reschedule', [ClientBookingController::class, 'reschedule'])->whereUuid('uuid');
            Route::get('appointments/{uuid}/queue', [ClientBookingController::class, 'queue'])->whereUuid('uuid');
            Route::post('appointments/{uuid}/review', [ClientBookingController::class, 'review'])->whereUuid('uuid');
            Route::get('reviews', [ClientBookingController::class, 'reviews']);
            Route::get('spas/{branchUuid}/booking-status', [ClientBookingController::class, 'bookingStatus'])->whereUuid('branchUuid');

            Route::get('favorites', [ClientAccountController::class, 'favorites']);
            Route::put('favorites/{branchUuid}', [ClientAccountController::class, 'addFavorite'])->whereUuid('branchUuid');
            Route::delete('favorites/{branchUuid}', [ClientAccountController::class, 'removeFavorite'])->whereUuid('branchUuid');

            Route::get('transactions', [ClientAccountController::class, 'transactions']);

            // Customer programs: points, vouchers, discounts and memberships
            // per spa, one spa's rewards for its page, points → voucher, and
            // one spa's points and rewards history.
            Route::get('rewards', [ClientRewardsController::class, 'index']);
            Route::post('rewards/redeem', [ClientRewardsController::class, 'redeem']);
            Route::get('spas/{branchUuid}/rewards', [ClientRewardsController::class, 'forBranch'])->whereUuid('branchUuid');
            Route::get('rewards/{businessUuid}/history', [ClientRewardsController::class, 'history'])->whereUuid('businessUuid');

            Route::get('notifications', [ClientAccountController::class, 'notifications']);
            Route::post('notifications/read-all', [ClientAccountController::class, 'markAllNotificationsRead']);
            Route::patch('notifications/{id}/read', [ClientAccountController::class, 'markNotificationRead'])->whereNumber('id');
        });

    // system administrator access route
    Route::prefix('system')
        ->middleware(['admin.ip', 'role:system_administrator', 'admin.2fa'])
        ->group(function () {
            // Every route below is gated by the administrator's own
            // permission flags (EnsurePermission + App\Support\AdminPermissions);
            // the Settings → Security self-service routes further down are
            // the caller's own account and stay open to every admin.
            Route::get('subscription-plans', [SubscriptionPlanController::class, 'index'])->middleware('permission:subscription_plan_view');
            Route::get('subscription-plans/{subscription_plan}', [SubscriptionPlanController::class, 'show'])->middleware('permission:subscription_plan_view');
            Route::post('subscription-plans', [SubscriptionPlanController::class, 'store'])->middleware('permission:subscription_plan_create');
            Route::match(['put', 'patch'], 'subscription-plans/{subscription_plan}', [SubscriptionPlanController::class, 'update'])->middleware('permission:subscription_plan_update');
            Route::delete('subscription-plans/{subscription_plan}', [SubscriptionPlanController::class, 'destroy'])->middleware('permission:subscription_plan_delete');
            // Who is subscribed and where each subscription stands (the
            // Subscribers tab beside Plans).
            Route::get('subscribers', [SubscriberController::class, 'index'])->middleware('permission:subscription_plan_view');

            Route::get('admin/user-management', [AdminUsersController::class, 'index'])->middleware('permission:admin_view');
            Route::get('admin/user-management/{user_management}', [AdminUsersController::class, 'show'])->middleware('permission:admin_view');
            Route::post('admin/user-management', [AdminUsersController::class, 'store'])->middleware('permission:admin_create');
            Route::match(['put', 'patch'], 'admin/user-management/{user_management}', [AdminUsersController::class, 'update'])->middleware('permission:admin_update');
            // "Delete" deactivates — see AdminUsersService::deactivate.
            Route::delete('admin/user-management/{user_management}', [AdminUsersController::class, 'destroy'])->middleware('permission:admin_delete');

            // The global service catalog owners start a new service from —
            // stored as services rows with is_template = true and no owning
            // business (see ServiceTemplateRepository).
            Route::get('service-templates', [ServiceTemplateController::class, 'index'])->middleware('permission:service_template_view');
            Route::get('service-templates/{uuid}', [ServiceTemplateController::class, 'show'])->middleware('permission:service_template_view');
            Route::post('service-templates', [ServiceTemplateController::class, 'store'])->middleware('permission:service_template_create');
            Route::match(['put', 'patch'], 'service-templates/{uuid}', [ServiceTemplateController::class, 'update'])->middleware('permission:service_template_update');
            Route::delete('service-templates/{uuid}', [ServiceTemplateController::class, 'destroy'])->middleware('permission:service_template_delete');

            Route::get('dashboard', [SystemDashboardController::class, 'index'])->middleware('permission:dashboard_view');

            Route::get('transactions', [TransactionController::class, 'index'])->middleware('permission:report_view');

            // Platform-wide configuration (e.g. the subscription grace
            // period used to compute the Transaction page's Overdue tile).
            Route::get('settings', [SystemSettingController::class, 'show']);
            Route::post('settings/preview', [SystemSettingController::class, 'preview'])->middleware('permission:setting_update');
            Route::patch('settings', [SystemSettingController::class, 'update'])->middleware('permission:setting_update');

            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_log_view');

            // The signed-in admin's own notification feed (TopBar bell +
            // Settings > Notifications) — see NotificationController.
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
            // "read" before "{id}" so it is never taken for an id.
            Route::delete('notifications/read', [NotificationController::class, 'clearRead']);
            Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id');
            Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereNumber('id');
            // Manual announcements (create, history, who they would reach, and the
            // searchable recipient lists). Kept apart from the automated notifications.
            Route::prefix('announcements')->middleware('permission:notification_send')->group(function () {
                Route::get('/', [AnnouncementController::class, 'index']);
                Route::post('/', [AnnouncementController::class, 'store']);
                Route::post('preview', [AnnouncementController::class, 'preview']);
                Route::get('recipient-options', [AnnouncementController::class, 'options']);
            });

            Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branch_view');
            Route::get('branches/{uuid}', [BranchController::class, 'show'])->middleware('permission:branch_view');
            Route::get('branches/{uuid}/suspension-impact', [BranchController::class, 'suspensionImpact'])->middleware('permission:branch_suspend');
            Route::post('branches/{uuid}/suspend', [BranchController::class, 'suspend'])->middleware('permission:branch_suspend');
            Route::post('branches/{uuid}/reactivate', [BranchController::class, 'reactivate'])->middleware('permission:branch_suspend');
            Route::get('businesses', [BusinessController::class, 'index'])->middleware('permission:spa_business_view');
            Route::get('businesses/{uuid}', [BusinessController::class, 'show'])->middleware('permission:spa_business_view');
            Route::get('businesses/{uuid}/programs', [BusinessController::class, 'programs'])->middleware('permission:spa_business_view');
            Route::get('businesses/{uuid}/suspension-impact', [BusinessController::class, 'suspensionImpact'])->middleware('permission:spa_business_suspend');
            Route::post('businesses/{uuid}/suspend', [BusinessController::class, 'suspend'])->middleware('permission:spa_business_suspend');
            Route::post('businesses/{uuid}/reactivate', [BusinessController::class, 'reactivate'])->middleware('permission:spa_business_suspend');

            // No store/update/destroy — a registration is only ever
            // reviewed (approve/reject), never created or edited here.
            Route::get('branch-registrations', [BranchRegistrationController::class, 'index'])->middleware('permission:branch_view');
            Route::get('branch-registrations/{uuid}', [BranchRegistrationController::class, 'show'])->middleware('permission:branch_view');

            Route::post('branch-registrations/{uuid}/approve', [BranchRegistrationController::class, 'approve'])->middleware('permission:branch_approve');
            Route::post('branch-registrations/{uuid}/reject', [BranchRegistrationController::class, 'reject'])->middleware('permission:branch_reject');

            // Owner identity and business verification are reviewed and
            // decided independently (see OwnerVerificationService) — one
            // "review" resource per account (SpaBusiness uuid), but
            // separate approve/reject actions for each half.
            Route::get('owner-verifications', [SystemOwnerVerificationController::class, 'index'])->middleware('permission:spa_business_view');
            Route::get('owner-verifications/{uuid}', [SystemOwnerVerificationController::class, 'show'])->middleware('permission:spa_business_view');

            Route::post('owner-verifications/{uuid}/identity/approve', [SystemOwnerVerificationController::class, 'approveIdentity'])->middleware('permission:spa_business_approve');
            Route::post('owner-verifications/{uuid}/identity/reject', [SystemOwnerVerificationController::class, 'rejectIdentity'])->middleware('permission:spa_business_reject');
            Route::post('owner-verifications/{uuid}/business/approve', [SystemOwnerVerificationController::class, 'approveBusiness'])->middleware('permission:spa_business_approve');
            Route::post('owner-verifications/{uuid}/business/reject', [SystemOwnerVerificationController::class, 'rejectBusiness'])->middleware('permission:spa_business_reject');

            // Settings → Security: self-service actions on the CALLER'S OWN
            // account only (change password, 2FA, personal verification
            // email, login sessions) — see SecurityService. Not to be
            // confused with admin/user-management above, which manages
            // OTHER administrators' accounts.
            Route::prefix('security')->group(function () {
                Route::post('change-password', [SecurityController::class, 'changePassword']);

                Route::get('two-factor', [SecurityController::class, 'twoFactorStatus']);
                Route::post('two-factor/enable', [SecurityController::class, 'enableTwoFactor'])->middleware('throttle:6,1');
                Route::post('two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])->middleware('throttle:10,1');
                Route::post('two-factor/disable', [SecurityController::class, 'disableTwoFactor']);

                Route::post('personal-email', [SecurityController::class, 'submitPersonalEmail']);
                Route::post('personal-email/verify', [SecurityController::class, 'verifyPersonalEmail'])->middleware('throttle:10,1');
                Route::post('personal-email/resend', [SecurityController::class, 'resendPersonalEmailOtp'])->middleware('throttle:6,1');

                Route::get('login-history', [SecurityController::class, 'loginHistory']);

                Route::get('sessions', [SecurityController::class, 'sessions']);
                // Every session except the caller's own.
                Route::delete('sessions', [SecurityController::class, 'revokeOtherSessions']);
                // Plain int, not {uuid} — personal_access_tokens.id is
                // Sanctum's own auto-increment key, the one deliberate
                // exception to this app's {uuid} route-param convention.
                Route::delete('sessions/{id}', [SecurityController::class, 'revokeSession']);
            });
        });

    // business access routes — split by role. Manager shares the owner's
    // dashboard and Employee Management (see ROLE_HOME.manager on the
    // frontend and SpaBusinessRepository::findForUser, which resolves
    // "their" business the same way for both), but not billing, account
    // grants, or branch editing — those stay owner-only.
    // Every change made here is written to the business's Audit Log
    // (RecordBusinessActivity).
    Route::prefix('business')
        ->middleware('activity')
        ->group(function () {
            // Deliberately outside verified.business / subscribed.business: the
            // notices an owner most needs to see (verification decided, plan
            // overdue, plan changed) arrive exactly when those gates block
            // everything else.
            Route::middleware('role:business_owner,manager,front_officer')->group(function () {
                // Changes the open screens' data: asked every few seconds, so cheap by design.
                Route::get('live/pulse', [LivePulseController::class, 'show']);
                // The signed-in staff member's OWN feed — same
                // NotificationService as the admin's /system/notifications,
                // scoped to the caller's user_id.
                Route::get('notifications', [BusinessNotificationController::class, 'index'])->middleware('permission:notification_view');
                Route::post('notifications/read-all', [BusinessNotificationController::class, 'markAllRead']);
                Route::post('notifications/{id}/read', [BusinessNotificationController::class, 'markRead'])->whereNumber('id');
                // Deletes every notification the caller has already read.
                Route::delete('notifications/read', [BusinessNotificationController::class, 'clearRead']);
                Route::delete('notifications/{id}', [BusinessNotificationController::class, 'destroy'])->whereNumber('id');
                Route::get('notifications/{id}', [BusinessNotificationController::class, 'show'])->middleware('permission:notification_view')->whereNumber('id');

                // The signed-in account's OWN password (Profile → Security).
                // Same SecurityService as the admin's /system/security: the
                // current password must be given, and every other signed-in
                // device is logged out. Outside the verification and
                // subscription gates on purpose — a password must always be
                // changeable.
                Route::post('account/change-password', [SecurityController::class, 'changePassword'])->middleware('throttle:10,1');
            });

            // 'verified.business' is the real enforcement of the
            // verification-onboarding gate — this group is what actually
            // renders the dashboard, so it's the point where an unverified
            // account must be blocked (see EnsureBusinessVerified). Owner
            // onboarding/verification submission and account/subscription
            // routes below deliberately stay outside this middleware so
            // they remain reachable while unverified.
            // 'subscribed.business' is the equivalent enforcement for "the
            // business must have an active subscription" — it must run
            // after verified.business (a business isn't resolved/verified
            // yet otherwise) and only ever acts on business_owner (see
            // EnsureBusinessSubscribed), so a manager passes through
            // unaffected here exactly like today.
            Route::middleware(['role:business_owner,manager', 'verified.business', 'subscribed.business'])->group(function () {
                Route::get('plan-access', [PlanAccessController::class, 'show']);
                Route::get('reports/export-data', [PlanAccessController::class, 'export'])->middleware(['permission:report_view', 'permission:report_export']);
                Route::post('reports/export-authorize', [PlanAccessController::class, 'authorizeExport'])->middleware('permission:report_export');
                // Each staff action needs its own permission: the owner turns
                // them on and off per account (EnsurePermission).
                Route::apiResource('staff', StaffController::class)->only(['index', 'show'])->middleware('permission:staff_view');
                Route::apiResource('staff', StaffController::class)->only(['store'])->middleware('permission:staff_create');
                Route::apiResource('staff', StaffController::class)->only(['update'])->middleware('permission:staff_update');
                Route::apiResource('staff', StaffController::class)->only(['destroy'])->middleware('permission:staff_delete');

                // Business → Audit Log: the owner's whole business, or the
                // branches a manager runs (BusinessAuditLogService).
                Route::get('audit-log', [BusinessAuditLogController::class, 'index'])->middleware('permission:audit_log_view');
                // The commission plans a staff member can be given: read by the
                // owner and managers, managed by the owner (below).
                Route::get('commission-plan', [CommissionPlanController::class, 'index']);

                // Mutation + the single-day operational roster stay
                // manager-only — the Owner gets a separate read-only
                // analytics report below (attendance/report) instead. Real
                // enforcement, not just a hidden UI, per the Owner
                // Attendance Analytics feature.
                // The owner runs attendance too (everything a manager does).
                Route::middleware('role:business_owner,manager')->group(function () {
                    Route::apiResource('attendance', AttendanceController::class)->only(['index'])->middleware('permission:attendance_view');
                    Route::apiResource('attendance', AttendanceController::class)->only(['store'])->middleware('permission:attendance_create');
                    Route::apiResource('attendance', AttendanceController::class)->only(['destroy'])->middleware('permission:attendance_delete');
                    Route::post('attendance/bulk-mark-present', [AttendanceController::class, 'bulkMarkPresent'])->middleware('permission:attendance_create');
                    Route::get('attendance/roster', [AttendanceController::class, 'roster'])->middleware('permission:attendance_view');
                });
                Route::get('attendance/report', [AttendanceController::class, 'report'])->middleware('permission:attendance_view');
                // attendance/{uuid} is registered after every literal
                // attendance/... segment above (roster, report,
                // bulk-mark-present) so Laravel matches those first instead
                // of swallowing them as a {uuid}.
                Route::middleware('role:business_owner,manager')->group(function () {
                    Route::get('attendance/{uuid}', [AttendanceController::class, 'show'])->middleware('permission:attendance_view');
                    Route::patch('attendance/{uuid}', [AttendanceController::class, 'update'])->middleware('permission:attendance_update');
                });

                Route::apiResource('branch', SpaBranchController::class)->only(['index', 'show'])->middleware('permission:branch_view');
                Route::apiResource('facility', FacilityController::class)->only(['index', 'show'])->middleware('permission:facility_view');
                Route::apiResource('facility', FacilityController::class)->only(['store'])->middleware('permission:facility_create');
                Route::apiResource('facility', FacilityController::class)->only(['update'])->middleware('permission:facility_update');
                Route::apiResource('facility', FacilityController::class)->only(['destroy'])->middleware('permission:facility_delete');
                Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard_view');

                // The catalog is shared by every branch, so a manager can only
                // read it here — creating, editing and retiring entries stay
                // owner-only below. What a manager controls is their own
                // branch's availability and price (the /branches routes next).
                Route::apiResource('service', ServiceController::class)->only(['index', 'show'])->middleware('permission:service_view');
                Route::apiResource('package', PackageController::class)->only(['index', 'show'])->middleware('permission:package_view');
                // Add-ons: extras put on a service during the visit.
                Route::apiResource('addon', AddonController::class)->only(['index', 'show'])->middleware('permission:service_view');

                // Per-branch availability toggle (branch_services/branch_packages) —
                // scoped server-side to the caller's own branches (manager can only
                // ever submit rows for their own staff record's branch).
                // Service-side is scoped to one variant (a specific duration/price
                // option), not the whole service, since price/availability live
                // per variant now.
                Route::patch('service/variant/{uuid}/branches', [ServiceController::class, 'updateVariantBranches'])->middleware('permission:branch_update');
                Route::patch('package/{uuid}/branches', [PackageController::class, 'updateBranches'])->middleware(['permission:branch_update', 'plan.feature:package_access']);
                // The same, for one branch at a time: every service and
                // package change saved in one request (the picker in Branch
                // Settings → Services & Packages).
                Route::patch('branch/{uuid}/offerings', [BranchOfferingController::class, 'update'])->middleware('permission:branch_update');

                // A manager asking the owner for a service or package the
                // catalog doesn't have. Only a manager asks; only the owner
                // answers (both checked in CatalogRequestService).
                Route::get('catalog-requests', [CatalogRequestController::class, 'index'])->middleware('permission:service_view');
                Route::post('catalog-requests', [CatalogRequestController::class, 'store'])->middleware(['permission:service_view', 'throttle:20,1']);
                Route::delete('catalog-requests/{uuid}', [CatalogRequestController::class, 'destroy'])->middleware('permission:service_view')->whereUuid('uuid');
                Route::post('catalog-requests/{uuid}/added', [CatalogRequestController::class, 'added'])->middleware(['role:business_owner', 'permission:service_create'])->whereUuid('uuid');
                Route::post('catalog-requests/{uuid}/decline', [CatalogRequestController::class, 'decline'])->middleware(['role:business_owner', 'permission:service_create'])->whereUuid('uuid');

                // A manager's own notification switches: which of the owner's
                // changes at their branch they are told about.
                Route::middleware('role:manager')->group(function () {
                    Route::get('notification-preferences', [ManagerNotificationPrefsController::class, 'show']);
                    Route::patch('notification-preferences', [ManagerNotificationPrefsController::class, 'update']);
                });

                // Manager can view and edit hours for their own branch —
                // BranchScheduleService scopes through branchesForUser the
                // same way FacilityService does, so a manager can never see
                // or touch another branch's schedule. Needed for full parity
                // with the Owner's Marketplace Listing access (its Booking &
                // Policies quick-edit reads/writes this).
                Route::apiResource('branch-schedule', BranchScheduleController::class)->only(['index', 'show'])->middleware('permission:branch_view');
                Route::apiResource('branch-schedule', BranchScheduleController::class)->only(['store', 'update', 'destroy'])->middleware('permission:branch_update');
                // The whole week at once — what the hours editors save with.
                Route::put('branch/{uuid}/schedule', [BranchScheduleController::class, 'saveWeek'])->middleware('permission:branch_update');

                // Temporary closures outside the weekly hours (a holiday,
                // repairs). The owner for any branch, a manager for their
                // own — scoped in BranchClosureService like the hours above.
                Route::get('branch/{uuid}/closures', [BranchClosureController::class, 'index'])->middleware('permission:branch_view');
                Route::get('branch/{uuid}/closures/conflicts', [BranchClosureController::class, 'conflicts'])->middleware('permission:branch_view');
                Route::post('branch/{uuid}/closures', [BranchClosureController::class, 'store'])->middleware('permission:branch_update');
                Route::patch('branch/{uuid}/closures/{closure}', [BranchClosureController::class, 'update'])->middleware('permission:branch_update')->whereUuid('closure');
                Route::delete('branch/{uuid}/closures/{closure}', [BranchClosureController::class, 'destroy'])->middleware('permission:branch_update')->whereUuid('closure');

                // Staff service qualifications (staff_services) — which
                // services a therapist is qualified to perform, consumed by
                // AppointmentAvailabilityService::isStaffQualified(). Staff
                // management stays owner/manager-only, unlike the
                // day-to-day appointment operations below.
                //
                // Whole-set replace on PATCH, same idiom as the schedule pair
                // below — and permission-gated the same way now that there's a
                // manager UI reading and writing this (both business_owner and
                // manager already hold staff_view/staff_update in
                // config/permission.php, so this narrows nothing).
                Route::get('staff/{uuid}/services', [StaffController::class, 'services'])->middleware('permission:staff_view');
                // What one staff member earned between two dates, each
                // commission line (the owner's and manager's employee page).
                Route::get('staff/{uuid}/earnings', [StaffController::class, 'earnings'])
                    ->middleware(['role:business_owner,manager', 'permission:staff_view'])->whereUuid('uuid');
                // Every staff member's earnings for a pay period, less
                // attendance penalties (the Earnings page).
                Route::get('earnings', [StaffEarningsController::class, 'index'])
                    ->middleware(['role:business_owner,manager', 'permission:commission_view']);
                Route::patch('staff/{uuid}/services', [StaffController::class, 'updateServices'])->middleware('permission:staff_update');

                // A staff member's current weekly schedule (working hours,
                // day-offs, breaks) — whole-set replace, not per-day CRUD, since
                // staff_schedules has no unique index (effective_from/until stay
                // reserved for a future versioning feature). Read by
                // AppointmentAvailabilityService::staffMatchesSchedule() and
                // AttendanceStatusCalculator for scheduled-hours/late/absent
                // detection.
                Route::get('staff/{uuid}/schedule', [StaffScheduleController::class, 'show'])->middleware('permission:staff_view');
                Route::patch('staff/{uuid}/schedule', [StaffScheduleController::class, 'update'])->middleware('permission:staff_update');

                // A manager's schedule change waiting for the owner (Staff
                // Policies → "Owner approval for schedule changes"). Approve
                // and reject are owner-only (checked in StaffScheduleService).
                Route::get('schedule-requests', [StaffScheduleController::class, 'requests'])->middleware('permission:staff_view');
                Route::post('schedule-requests/{uuid}/approve', [StaffScheduleController::class, 'approveRequest'])->middleware(['role:business_owner', 'permission:staff_update'])->whereUuid('uuid');
                Route::post('schedule-requests/{uuid}/reject', [StaffScheduleController::class, 'rejectRequest'])->middleware(['role:business_owner', 'permission:staff_update'])->whereUuid('uuid');
                Route::delete('schedule-requests/{uuid}', [StaffScheduleController::class, 'withdrawRequest'])->middleware('permission:staff_update')->whereUuid('uuid');

                // Moving a staff member to another branch: the owner's applies
                // at once; a manager's is a request waiting here for the owner
                // (approve and decline are owner-only, checked in
                // StaffTransferService). Asked for by saving the employee's
                // Branch. branch-options lists every branch of the business,
                // which a manager's own branch list does not.
                Route::get('branch-options', [StaffTransferController::class, 'branchOptions'])->middleware('permission:staff_view');
                Route::get('transfer-requests', [StaffTransferController::class, 'index'])->middleware('permission:staff_view');
                Route::post('transfer-requests/{uuid}/approve', [StaffTransferController::class, 'approve'])->middleware(['role:business_owner', 'permission:staff_update'])->whereUuid('uuid');
                Route::post('transfer-requests/{uuid}/decline', [StaffTransferController::class, 'decline'])->middleware(['role:business_owner', 'permission:staff_update'])->whereUuid('uuid');
                Route::delete('transfer-requests/{uuid}', [StaffTransferController::class, 'withdraw'])->middleware('permission:staff_update')->whereUuid('uuid');
            });

            // Day-to-day front-desk operations: appointment creation,
            // check-in, service/therapist/room assignment, queueing,
            // service execution, and billing/payment. front_officer has
            // zero routes anywhere else in this file despite already having
            // a permission set defined in config/permission.php for exactly
            // this — see EnsurePermission (aliased 'permission') for how
            // those keys are enforced below. Actions with no matching
            // front_officer key yet (cancel, no-show, assign therapist/room,
            // start/complete service, add/remove service, add package) stay
            // gated at this role-group level only — see
            // config/permission.php's front_officer array for the gap.
            Route::middleware(['role:business_owner,manager,front_officer', 'verified.business', 'subscribed.business'])->group(function () {
                Route::get('client', [ClientController::class, 'index'])->middleware('permission:client_view');
                Route::post('client', [ClientController::class, 'store'])->middleware('permission:client_create');
                Route::get('client/{uuid}', [ClientController::class, 'show'])->middleware('permission:client_view');
                Route::patch('client/{uuid}', [ClientController::class, 'update'])->middleware('permission:client_update');
                // Takes the client's picture down at this spa (their account keeps it).
                Route::delete('client/{uuid}/photo', [ClientController::class, 'dismissPhoto'])->middleware('permission:client_update');

                // Standing preferred-therapist preference — gated separately
                // from client_update so manager can manage this one field
                // without full client edit rights (business_owner is in this
                // role group but still 403s, same as it already lacks
                // client_create/client_update).
                Route::patch('client/{uuid}/preferred-therapist', [ClientController::class, 'setPreferredTherapist'])
                    ->middleware('permission:client_therapist_manage');

                // Visits left open from days that are over: how many, and close them now.
                Route::get('past-visits', [AppointmentController::class, 'pastVisits'])->middleware('permission:appointment_view');
                Route::post('past-visits/close', [AppointmentController::class, 'closePastVisits'])->middleware('permission:appointment_update');
                Route::get('appointment', [AppointmentController::class, 'index'])->middleware('permission:appointment_view');
                Route::post('appointment', [AppointmentController::class, 'store'])->middleware('permission:appointment_create');
                Route::get('appointment/{uuid}', [AppointmentController::class, 'show'])->middleware('permission:appointment_view');
                Route::post('appointment/{uuid}/checkin', [AppointmentController::class, 'checkIn'])->middleware('permission:appointment_checkin');
                Route::post('appointment/{uuid}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:appointment_cancel');
                Route::post('appointment/{uuid}/no-show', [AppointmentController::class, 'markNoShow'])->middleware('permission:appointment_cancel');
                Route::patch('appointment/{uuid}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:appointment_update');
                Route::post('appointment/{uuid}/services', [AppointmentController::class, 'addService'])->middleware('permission:appointment_update');
                Route::delete('appointment-service/{uuid}', [AppointmentController::class, 'removeService'])->middleware('permission:appointment_update');
                // Taking a started or finished service off the visit changes
                // what is charged: for staff allowed to change a bill.
                Route::post('appointment-service/{uuid}/void', [AppointmentController::class, 'voidService'])->middleware('permission:billing_update');
                Route::post('appointment/{uuid}/packages', [AppointmentController::class, 'addPackage'])->middleware(['permission:appointment_update', 'plan.feature:package_access,open']);
                Route::post('appointment/{uuid}/additional-services', [AppointmentServiceController::class, 'addAdditionalService'])->middleware('permission:appointment_update');
                Route::post('appointment/{uuid}/billing', [AppointmentController::class, 'proceedToBilling'])->middleware('permission:billing_create');

                // The whole booking at once for the Assign drawer: every therapist and facility against every
                // service still to do, and the saving of all of it together (all or nothing).
                Route::get('appointment/{uuid}/assignment-options', [AppointmentServiceController::class, 'assignmentOptions'])->middleware('permission:appointment_view');
                Route::post('appointment/{uuid}/auto-assign', [AppointmentServiceController::class, 'autoAssign'])->middleware('permission:appointment_update');
                Route::post('appointment/{uuid}/assignments/batch', [AppointmentServiceController::class, 'assignBatch'])->middleware('permission:appointment_update');
                Route::get('appointment-service/{uuid}/therapist-options', [AppointmentServiceController::class, 'therapistOptions'])->middleware('permission:appointment_view');
                // Every facility with why it can't take this service (booked, maintenance, not set up).
                Route::get('appointment-service/{uuid}/facility-options', [AppointmentServiceController::class, 'facilityOptions'])->middleware('permission:appointment_view');
                Route::post('appointment-service/{uuid}/assignments', [AppointmentServiceController::class, 'assignTherapist'])->middleware('permission:appointment_update');
                Route::delete('assignment/{uuid}', [AppointmentServiceController::class, 'cancelAssignment'])->middleware('permission:appointment_update');
                Route::patch('assignment/{uuid}/room', [AppointmentServiceController::class, 'assignRoom'])->middleware('permission:appointment_update');
                // Clears just the room, keeping the therapist on the assignment.
                Route::delete('assignment/{uuid}/room', [AppointmentServiceController::class, 'clearRoom'])->middleware('permission:appointment_update');
                Route::post('appointment-service/{uuid}/start', [AppointmentServiceController::class, 'startService'])->middleware('permission:appointment_complete');
                Route::post('appointment-service/{uuid}/complete', [AppointmentServiceController::class, 'completeService'])->middleware('permission:appointment_complete');
                // The client wants longer: a longer length of the same service.
                Route::get('appointment-service/{uuid}/extend-options', [AppointmentServiceController::class, 'extendOptions'])->middleware('permission:appointment_view');
                Route::post('appointment-service/{uuid}/extend', [AppointmentServiceController::class, 'extendService'])->middleware('permission:appointment_update');
                // An add-on on this service, from the ones available for it.
                Route::post('appointment-service/{uuid}/addons', [AppointmentServiceController::class, 'addAddon'])->middleware('permission:appointment_update');

                Route::get('queue', [QueueController::class, 'index'])->middleware('permission:queue_view');
                Route::post('appointment/{uuid}/queue/call', [AppointmentController::class, 'callQueue'])->middleware('permission:queue_update');
                Route::post('appointment/{uuid}/queue/skip', [AppointmentController::class, 'skipQueue'])->middleware('permission:queue_update');
                Route::post('appointment/{uuid}/queue/recall', [AppointmentController::class, 'recallQueue'])->middleware('permission:queue_update');

                Route::get('billings', [BillingController::class, 'index'])->middleware('permission:billing_view');
                Route::get('billing/{uuid}', [BillingController::class, 'show'])->middleware('permission:billing_view');
                Route::post('billing/{uuid}/payments', [PaymentController::class, 'store'])->middleware('permission:payment_create');
                // Methods the owner accepts (Settings → Payments), for the payment dialog.
                Route::get('payment-options', [BillingController::class, 'paymentOptions'])->middleware('permission:payment_create');
                Route::patch('billing/{uuid}/discount', [BillingController::class, 'discount'])->middleware('permission:billing_update');
                // Customer programs at checkout — vouchers and discount programs.
                Route::get('billing/{uuid}/program-options', [BillingController::class, 'programOptions'])->middleware('permission:reward_view');
                Route::patch('billing/{uuid}/program-discount', [BillingController::class, 'programDiscount'])->middleware(['permission:reward_update', 'plan.feature:reward_access,open']);
                Route::post('billing/{uuid}/refund', [BillingController::class, 'refund'])->middleware('permission:payment_refund');
                Route::post('payment/{uuid}/void', [PaymentController::class, 'void'])->middleware('permission:payment_refund');
                // Changing a bill after it is paid (a voucher afterwards, a
                // refund): applied at once, or a request a manager or the
                // owner answers — who may do which is checked in
                // BillingAdjustmentService (App\Support\BillAdjustment).
                Route::get('billing-adjustments', [BillingAdjustmentController::class, 'index'])->middleware('permission:billing_view');
                Route::get('refund-policies', [RefundPolicyController::class, 'index'])->middleware('permission:billing_view');
                Route::post('billing/{uuid}/adjustments', [BillingAdjustmentController::class, 'store'])->middleware(['permission:payment_create', 'throttle:30,1']);
                Route::delete('billing-adjustments/{uuid}', [BillingAdjustmentController::class, 'destroy'])->middleware('permission:payment_create')->whereUuid('uuid');
                Route::post('billing-adjustments/{uuid}/approve', [BillingAdjustmentController::class, 'approve'])
                    ->middleware(['role:business_owner,manager', 'permission:payment_create'])->whereUuid('uuid');
                Route::post('billing-adjustments/{uuid}/decline', [BillingAdjustmentController::class, 'decline'])
                    ->middleware(['role:business_owner,manager', 'permission:payment_create'])->whereUuid('uuid');

                Route::get('frontoffice/dashboard', [FrontOfficeDashboardController::class, 'index'])->middleware('permission:dashboard_view');

                // Front Desk's narrow attendance surface — today only,
                // check-in/check-out only (no arbitrary status/date). See
                // FrontOfficeAttendanceService for why this is separate from
                // the Manager/Owner attendance routes above rather than a
                // relaxed role gate on those.
                Route::get('frontoffice/attendance', [FrontOfficeAttendanceController::class, 'index'])->middleware('permission:attendance_view');
                Route::post('frontoffice/attendance/check-in', [FrontOfficeAttendanceController::class, 'checkIn'])->middleware('permission:attendance_checkin');
                Route::post('frontoffice/attendance/check-out', [FrontOfficeAttendanceController::class, 'checkOut'])->middleware('permission:attendance_checkin');
                // Sending a therapist home early / marking them on leave today,
                // and who could fill in for them (lowest earnings first).
                Route::post('frontoffice/attendance/leave', [FrontOfficeAttendanceController::class, 'leave'])->middleware('permission:attendance_checkin');
                Route::get('frontoffice/attendance/fill-in-candidates', [FrontOfficeAttendanceController::class, 'fillInCandidates'])->middleware('permission:attendance_view');
                // After every literal frontoffice/attendance/... segment above.
                Route::get('frontoffice/attendance/{uuid}/history', [FrontOfficeAttendanceController::class, 'history'])->middleware('permission:attendance_view')->whereUuid('uuid');

                // Customer programs (loyalty · vouchers · discounts · memberships).
                // Reading is open to every business role with reward_view (a
                // manager/front desk sees what's offered at their branch);
                // defining programs is the owner's; switching one on/off for a
                // branch is owner + manager; selling memberships and redeeming
                // points is the counter's job too. Writes need a plan that
                // includes rewards.
                Route::get('programs', [CustomerProgramController::class, 'index'])->middleware('permission:reward_view');
                Route::get('programs/memberships', [CustomerProgramController::class, 'memberships'])->middleware('permission:reward_view');
                Route::get('client/{uuid}/rewards', [CustomerProgramController::class, 'clientRewards'])->middleware('permission:reward_view');
                Route::get('client/{uuid}/vouchers', [CustomerProgramController::class, 'clientVouchers'])->middleware('permission:reward_view');
                // A manager asking the owner for a new voucher, discount or
                // membership for their branch. Only a manager asks; only the
                // owner answers (checked again in ProgramRequestService).
                Route::get('program-requests', [ProgramRequestController::class, 'index'])
                    ->middleware(['role:business_owner,manager', 'permission:reward_view']);
                Route::middleware('plan.feature:reward_access')->group(function () {
                    Route::post('program-requests', [ProgramRequestController::class, 'store'])
                        ->middleware(['role:manager', 'permission:reward_view', 'throttle:20,1']);
                    Route::delete('program-requests/{uuid}', [ProgramRequestController::class, 'destroy'])
                        ->middleware(['role:business_owner,manager', 'permission:reward_update'])->whereUuid('uuid');
                    Route::post('program-requests/{uuid}/approve', [ProgramRequestController::class, 'approve'])
                        ->middleware(['role:business_owner', 'permission:reward_create'])->whereUuid('uuid');
                    Route::post('program-requests/{uuid}/decline', [ProgramRequestController::class, 'decline'])
                        ->middleware(['role:business_owner', 'permission:reward_create'])->whereUuid('uuid');
                });
                Route::middleware('plan.feature:reward_access')->group(function () {
                    Route::middleware('role:business_owner')->group(function () {
                        Route::patch('programs/module', [CustomerProgramController::class, 'module'])->middleware('permission:reward_update');
                        Route::post('programs', [CustomerProgramController::class, 'store'])->middleware('permission:reward_create');
                        Route::patch('programs/{uuid}', [CustomerProgramController::class, 'update'])->middleware('permission:reward_update')->whereUuid('uuid');
                        Route::delete('programs/{uuid}', [CustomerProgramController::class, 'destroy'])->middleware('permission:reward_delete')->whereUuid('uuid');
                    });
                    Route::put('programs/{uuid}/branches/{branchUuid}', [CustomerProgramController::class, 'branch'])
                        ->middleware(['role:business_owner,manager', 'permission:reward_update'])->whereUuid('uuid');
                    Route::post('programs/memberships', [CustomerProgramController::class, 'sellMembership'])->middleware('permission:reward_update');
                    Route::post('programs/memberships/{uuid}/renew', [CustomerProgramController::class, 'renewMembership'])->middleware('permission:reward_update');
                    Route::post('programs/memberships/{uuid}/cancel', [CustomerProgramController::class, 'cancelMembership'])->middleware('permission:reward_update');
                    Route::post('client/{uuid}/rewards/redeem', [CustomerProgramController::class, 'redeem'])->middleware('permission:reward_update');
                });

                Route::get('frontoffice/branches', [FrontOfficeLookupController::class, 'branches']);
                Route::get('frontoffice/therapists', [FrontOfficeLookupController::class, 'therapists'])->middleware('permission:appointment_view');
                Route::get('frontoffice/therapists/{uuid}', [FrontOfficeLookupController::class, 'therapist'])->middleware('permission:appointment_view');
                // Today's therapist rotation (who's next in line) — a read of
                // attendance + completed assignments, no state of its own.
                // Role-gated only, like its sibling lookups above.
                Route::get('frontoffice/therapist-queue', [FrontOfficeLookupController::class, 'therapistQueue'])->middleware('permission:appointment_view');
                Route::get('frontoffice/availability-check', [FrontOfficeLookupController::class, 'availabilityCheck'])->middleware('permission:appointment_view');
                Route::get('frontoffice/facilities', [FrontOfficeLookupController::class, 'facilities'])->middleware('permission:appointment_view');
                Route::get('frontoffice/services', [FrontOfficeLookupController::class, 'services'])->middleware('permission:appointment_view');
                Route::get('frontoffice/packages', [FrontOfficeLookupController::class, 'packages'])->middleware('permission:appointment_view');
                Route::get('frontoffice/addons', [FrontOfficeLookupController::class, 'addons'])->middleware('permission:appointment_view');
                Route::get('frontoffice/schedule', [FrontOfficeLookupController::class, 'schedule'])->middleware('permission:appointment_view');
                // The branch's scanner link (staff start a home visit by
                // scanning the client's QR — see ScannerController).
                Route::get('frontoffice/scanner-link', [FrontOfficeLookupController::class, 'scannerLink'])->middleware('permission:attendance_view');
                Route::post('frontoffice/scanner-link/replace', [FrontOfficeLookupController::class, 'replaceScannerLink'])->middleware('permission:attendance_checkin');
                Route::get('frontoffice/booked-times', [FrontOfficeLookupController::class, 'busyTimes'])->middleware('permission:appointment_view');
                // The New Appointment page: each therapist's free start times
                // for a day and visit length. Read-only, role-gated like its
                // sibling lookups above.
                Route::get('frontoffice/booking-board', [FrontOfficeLookupController::class, 'bookingBoard'])->middleware('permission:appointment_view');
            });

            // Branch Settings (web /business/settings/branch/*): the owner for
            // any of their branches, a manager for their own branch only —
            // BranchSettingsService resolves the uuid through the caller's
            // branchesForUser(), so any other branch is a 404.
            Route::middleware('role:business_owner,manager')->group(function () {
                Route::get('branch/{uuid}/marketplace', [BranchSettingsController::class, 'marketplace'])->middleware('permission:branch_view');
                Route::patch('branch/{uuid}/marketplace', [BranchSettingsController::class, 'updateMarketplace'])->middleware('permission:branch_update');
                Route::post('branch/{uuid}/photos', [BranchSettingsController::class, 'uploadPhotos'])->middleware('permission:branch_update');
                Route::delete('branch/{uuid}/photos/{photo}', [BranchSettingsController::class, 'deletePhoto'])->middleware('permission:branch_update');
                Route::post('branch/{uuid}/photos/{photo}/cover', [BranchSettingsController::class, 'setCover'])->middleware('permission:branch_update');
                Route::patch('branch/{uuid}/photos/order', [BranchSettingsController::class, 'reorderPhotos'])->middleware('permission:branch_update');
                Route::patch('branch/{uuid}/booking-policy', [BranchSettingsController::class, 'updateBookingPolicy'])->middleware('permission:branch_update');
                Route::post('branch/{uuid}/email/resend', [ContactEmailController::class, 'resendBranch'])->middleware(['permission:branch_update', 'throttle:6,1']);
                Route::get('branch/{uuid}/reviews-summary', [BranchSettingsController::class, 'reviewsSummary'])->middleware(['permission:review_view', 'plan.feature:review_access']);

                // Read side of Business Settings. A manager gets only what a
                // branch inherits (identity + booking defaults); every write
                // stays owner-only below.
                Route::get('settings', [BusinessSettingsController::class, 'show']);
            });

            Route::middleware('role:business_owner')->group(function () {
                Route::get('/me', [SpaBusinessController::class, 'me']);

                // Business Settings (company-wide configuration). Identity is
                // POST because it carries the logo as multipart.
                Route::prefix('settings')->group(function () {
                    Route::post('identity', [BusinessSettingsController::class, 'updateIdentity']);
                    Route::post('identity/email/resend', [ContactEmailController::class, 'resendBusiness'])->middleware('throttle:6,1');
                    Route::patch('legal', [BusinessSettingsController::class, 'updateLegal']);
                    Route::patch('payments', [BusinessSettingsController::class, 'updatePayments']);
                    Route::patch('staff-policy', [BusinessSettingsController::class, 'updateStaffPolicy']);
                    Route::patch('booking-defaults', [BusinessSettingsController::class, 'updateBookingDefaults']);
                    Route::patch('notifications', [BusinessSettingsController::class, 'updateNotifications']);
                });

                // Business Defaults → Refunds: the owner's refund policies
                // (App\Support\RefundPolicyRule). Read by everyone at
                // business/refund-policies; changed only here.
                Route::post('refund-policies', [RefundPolicyController::class, 'store']);
                Route::patch('refund-policies/settings', [RefundPolicyController::class, 'settings']);
                Route::patch('refund-policies/{uuid}', [RefundPolicyController::class, 'update'])->whereUuid('uuid');
                Route::delete('refund-policies/{uuid}', [RefundPolicyController::class, 'destroy'])->whereUuid('uuid');

                // Owner identity + business verification submission — must
                // stay reachable while the account is unverified (that's the
                // whole point of this flow), so it's deliberately outside
                // the 'verified.business'-gated group above. Now also covers
                // what used to be the separate "basic onboarding" step
                // (personal details, business creation) — there is no
                // longer a distinct onboarding phase, just the first
                // sub-steps of this same flow.
                Route::prefix('owner/verification')->group(function () {
                    Route::get('/', [BusinessOwnerVerificationController::class, 'show']);
                    Route::post('identity/personal', [BusinessOwnerVerificationController::class, 'savePersonalDetails']);
                    Route::post('identity', [BusinessOwnerVerificationController::class, 'saveIdentity']);
                    Route::post('identity/face-scan', [BusinessOwnerVerificationController::class, 'saveFaceScan']);
                    Route::post('business/details', [BusinessOwnerVerificationController::class, 'saveBusinessDetails']);
                    Route::post('business', [BusinessOwnerVerificationController::class, 'saveBusiness']);
                    Route::post('submit', [BusinessOwnerVerificationController::class, 'submit']);
                });

                // Ahead of the apiResource below on purpose: that registers
                // GET account/{account} with an unconstrained wildcard, so
                // declaring this afterwards would route /account/suggest into
                // show() with "suggest" as the uuid.
                Route::get('account/suggest', [AccountController::class, 'suggest']);

                // Upgrade / downgrade the live plan (PlanSwitchService). Before
                // the subscription resource, so subscription/{id} can't
                // swallow "change".
                // Servora's own checkout (Card / GCash / Maya via Xendit
                // Components), auto-renew and saved payment methods.
                Route::get('checkout/quote', [CheckoutController::class, 'quote']);
                Route::post('checkout', [CheckoutController::class, 'start'])->middleware('throttle:20,1');
                Route::get('checkout/{uuid}', [CheckoutController::class, 'status'])->whereUuid('uuid');
                Route::patch('subscription/auto-renew', [CheckoutController::class, 'setAutoRenew']);
                Route::post('payment-methods/{uuid}/default', [CheckoutController::class, 'makeDefaultMethod'])->whereUuid('uuid');
                Route::delete('payment-methods/{uuid}', [CheckoutController::class, 'removeMethod'])->whereUuid('uuid');

                // Just the plan's limits + current usage, for the Branches and
                // Accounts pages' Plan Capacity card (the full subscription
                // read also settles payments and loads billing history).
                Route::get('subscription/capacity', [SubscriptionController::class, 'capacity']);

                // Start the one free trial (config/trial.php).
                Route::post('subscription/trial', [SubscriptionController::class, 'startTrial'])->middleware('throttle:10,1');

                Route::get('subscription/change/quote', [SubscriptionController::class, 'quotePlanChange']);
                Route::post('subscription/change', [SubscriptionController::class, 'changePlan']);
                Route::delete('subscription/change', [SubscriptionController::class, 'cancelScheduledPlanChange']);

                // Owners read and buy subscriptions only — the plan, dates and
                // status change through payments and plan switches, never by
                // editing the row directly.
                Route::apiResource('subscription', SubscriptionController::class)->only(['index', 'store']);
                Route::apiResource('account', AccountController::class);
                Route::apiResource('branch', SpaBranchController::class)->except(['index', 'show']);
                // Read-only view of the admin's catalog, shown when the owner
                // clicks Add Service. Adopting one is just the normal
                // POST service below carrying source_template_uuid — there is
                // no separate adopt endpoint.
                Route::get('service-templates', [ServiceController::class, 'templates']);
                Route::apiResource('service', ServiceController::class)->only(['store', 'update', 'destroy']);
                Route::apiResource('addon', AddonController::class)->only(['store', 'update', 'destroy']);
                Route::apiResource('commission-plan', CommissionPlanController::class)->only(['store', 'update', 'destroy']);
                // Managing packages needs a plan that includes them. Reading
                // the list stays open (the page shows what the plan is missing).
                Route::apiResource('package', PackageController::class)->only(['store', 'update', 'destroy'])->middleware('plan.feature:package_access');

                Route::post('branch/{uuid}/reverify', [SpaBranchController::class, 'reverify']);
                Route::post('branch/{uuid}/location', [SpaBranchController::class, 'saveLocation']);
                Route::post('branch/{uuid}/permit', [SpaBranchController::class, 'savePermit']);
                Route::post('branch/{uuid}/submit', [SpaBranchController::class, 'submit']);
                // A branch or staff login a smaller plan put on hold comes
                // back here, once the plan has room (PlanFitService).
                Route::post('branch/{uuid}/bring-back', [SpaBranchController::class, 'bringBack'])->whereUuid('uuid');
                Route::post('account/{uuid}/bring-back', [AccountController::class, 'bringBack'])->whereUuid('uuid');

                Route::get('subscription/confirm/{referenceId}', [SubscriptionController::class, 'confirm']);
                // Accept / decline an admin's update to the subscribed plan
                // (PlanChangeService).
                Route::post('subscription/plan-change', [SubscriptionController::class, 'respondToPlanChange']);
            });
        });
});

// System Handle Part

// The button in the sign-up email: the account is created when it is pressed
// (App\Support\PendingRegistration).
Route::get('/email/register/{token}', [EmailVerificationController::class, 'completeRegistration'])
    ->middleware(['signed', 'throttle:30,1'])->name('registration.complete');

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');

// The button in the email that confirms a business's or branch's contact email
// (App\Support\ContactEmail). Signed, so it can't be guessed or reused for
// another address.
Route::get('/contact-email/verify/{kind}/{uuid}/{hash}', [ContactEmailController::class, 'verify'])
    ->middleware(['signed', 'throttle:30,1'])->name('contact-email.verify');

// xendit webhook (server-to-server, no auth — verified via x-callback-token)
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
