<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\System\AdminUsersController;
use App\Http\Controllers\System\TransactionController;
use App\Http\Controllers\Business\DashboardController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\Business\BranchScheduleController;
use App\Http\Controllers\Business\SpaBranchController;
use App\Http\Controllers\Business\AccountController;
use App\Http\Controllers\Business\StaffController;
use App\Http\Controllers\Business\FacilityController;
use App\Http\Controllers\Business\ServiceController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\SpaBusinessController;
use App\Http\Controllers\System\BranchRegistrationController;
use App\Http\Controllers\Business\OwnerVerificationController as BusinessOwnerVerificationController;
use App\Http\Controllers\System\OwnerVerificationController as SystemOwnerVerificationController;

// authentication part
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [RegisterController::class, 'registerClient']);
Route::post('/auth/forget-password', [AuthController::class, 'forgetPassword']);
Route::post('/auth/forget-password/verify-otp', [AuthController::class, 'verifyForgetPasswordOtp']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/business/administrator/register', [RegisterController::class, 'register']);


// display available subscription plan
Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);

// Public, unauthenticated business lookup — backs the branded
// /login/{business_uuid} page on the frontend (see PublicSpaBusinessResource
// for what's exposed here).
Route::get('/business/{uuid}/public', [SpaBusinessController::class, 'publicShow']);


// Private verification documents (government ID front/back, face-scan
// frames, business registration document) are served directly by
// Cloudinary now — see DocumentUploadService::signedUrl() — so there is no
// local retrieval route for them anymore.


// auth sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'getUser']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);


    // system administrator access route
    Route::prefix('system')
        ->middleware('role:system_administrator')
        ->group(function () {
            Route::apiResources([
                'subscription-plans' => SubscriptionPlanController::class,
                'admin/user-management' => AdminUsersController::class
            ]);

            Route::get('transactions', [TransactionController::class, 'index']);

            // No store/update/destroy — a registration is only ever
            // reviewed (approve/reject), never created or edited here.
            Route::apiResource('branch-registrations', BranchRegistrationController::class)
                ->parameters(['branch-registrations' => 'uuid'])
                ->only(['index', 'show']);

            Route::post('branch-registrations/{uuid}/approve', [BranchRegistrationController::class, 'approve']);
            Route::post('branch-registrations/{uuid}/reject', [BranchRegistrationController::class, 'reject']);

            // Owner identity and business verification are reviewed and
            // decided independently (see OwnerVerificationService) — one
            // "review" resource per account (SpaBusiness uuid), but
            // separate approve/reject actions for each half.
            Route::apiResource('owner-verifications', SystemOwnerVerificationController::class)
                ->parameters(['owner-verifications' => 'uuid'])
                ->only(['index', 'show']);

            Route::post('owner-verifications/{uuid}/identity/approve', [SystemOwnerVerificationController::class, 'approveIdentity']);
            Route::post('owner-verifications/{uuid}/identity/reject', [SystemOwnerVerificationController::class, 'rejectIdentity']);
            Route::post('owner-verifications/{uuid}/business/approve', [SystemOwnerVerificationController::class, 'approveBusiness']);
            Route::post('owner-verifications/{uuid}/business/reject', [SystemOwnerVerificationController::class, 'rejectBusiness']);
        });

    
    // business access routes — split by role. Manager shares the owner's
    // dashboard and Employee Management (see ROLE_HOME.manager on the
    // frontend and SpaBusinessRepository::findForUser, which resolves
    // "their" business the same way for both), but not billing, account
    // grants, or branch editing — those stay owner-only.
    Route::prefix('business')
        ->group(function () {
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
                Route::apiResource('staff', StaffController::class);
                Route::apiResource('branch', SpaBranchController::class)->only(['index', 'show']);
                Route::apiResource('facility', FacilityController::class);
                Route::get('dashboard', [DashboardController::class, 'index']);

                // Manager can view and edit the catalog (service_view/service_update,
                // package_view/package_update in config/permission.php) but not
                // create or retire entries — those stay owner-only below.
                Route::apiResource('service', ServiceController::class)->only(['index', 'show', 'update']);
                Route::apiResource('package', PackageController::class)->only(['index', 'show', 'update']);

                // Per-branch availability toggle (branch_services/branch_packages) —
                // reuses service_update/package_update rather than a new permission,
                // scoped server-side to the caller's own branches (manager can only
                // ever submit rows for their one AccountBranch-assigned branch).
                // Service-side is scoped to one variant (a specific duration/price
                // option), not the whole service, since price/availability live
                // per variant now.
                Route::patch('service/variant/{uuid}/branches', [ServiceController::class, 'updateVariantBranches']);
                Route::patch('package/{uuid}/branches', [PackageController::class, 'updateBranches']);

                // Manager can view and edit hours for their own branch —
                // BranchScheduleService scopes through branchesForUser the
                // same way FacilityService does, so a manager can never see
                // or touch another branch's schedule. Needed for full parity
                // with the Owner's Marketplace Listing access (its Booking &
                // Policies quick-edit reads/writes this).
                Route::apiResource('branch-schedule', BranchScheduleController::class);
            });

            Route::middleware('role:business_owner')->group(function () {
                Route::get('/me', [SpaBusinessController::class, 'me']);

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

                Route::apiResources([
                    'subscription' => SubscriptionController::class,
                    'account' => AccountController::class,
                ]);
                Route::apiResource('branch', SpaBranchController::class)->except(['index', 'show']);
                Route::apiResource('service', ServiceController::class)->only(['store', 'destroy']);
                Route::apiResource('package', PackageController::class)->only(['store', 'destroy']);

                Route::post('branch/{uuid}/registration', [SpaBranchController::class, 'submitRegistration']);

                Route::get('subscription/confirm/{referenceId}', [SubscriptionController::class, 'confirm']);
            });
        });
});


// System Handle Part   

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');

// xendit webhook (server-to-server, no auth — verified via x-callback-token)
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
