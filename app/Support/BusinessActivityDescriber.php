<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\Billing;
use App\Models\BranchClosure;
use App\Models\CatalogRequest;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\CommissionPlan;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\Package;
use App\Models\Payment;
use App\Models\ProgramRequest;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffScheduleChangeRequest;
use App\Models\StaffTransferRequest;
use App\Models\TherapistAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

// What each business action is called in the Audit Log (Business → Audit
// Log), keyed by the route's Controller@method: its exact key, the area it is
// filed under, the audit action, the record it is about and the sentence.
//
// A sentence names the record with {subject}; a part in [brackets] is left
// out when what it names isn't known ("Took a payment[ of {amount}] from
// {subject}"). A route missing from this list still gets a row, worded from
// its controller (fallback()) — tests/Unit/BusinessActivityDescriberTest and
// the route check in the probe keep the list complete.
final class BusinessActivityDescriber
{
    public const AREAS = ['bookings', 'billing', 'clients', 'staff', 'services', 'settings', 'accounts', 'sign_in'];

    // Not worth a row: a person's own notifications and notification switches.
    public const SKIPPED = [
        'NotificationController@markRead',
        'NotificationController@markAllRead',
        'NotificationController@destroy',
        'NotificationController@clearRead',
        'BusinessNotificationController@markRead',
        'BusinessNotificationController@markAllRead',
        'BusinessNotificationController@destroy',
        'BusinessNotificationController@clearRead',
        'ManagerNotificationPrefsController@update',
    ];

    // [event, area, action, the record it is about, sentence]
    public const MAP = [
        // ── Bookings ─────────────────────────────────────────────────────
        'AppointmentController@store' => ['appointment.create', 'bookings', 'Create', Appointment::class, 'Booked a visit[ for {subject}][ on {when}]'],
        'AppointmentController@reschedule' => ['appointment.reschedule', 'bookings', 'Update', Appointment::class, "Moved[ {subject}'s] booking[ to {when}]"],
        'AppointmentController@cancel' => ['appointment.cancel', 'bookings', 'Cancel', Appointment::class, "Cancelled[ {subject}'s] booking"],
        'AppointmentController@checkIn' => ['appointment.check_in', 'bookings', 'Check In', Appointment::class, 'Checked in[ {subject}]'],
        'AppointmentController@markNoShow' => ['appointment.no_show', 'bookings', 'Update', Appointment::class, 'Marked[ {subject}] as a no-show'],
        'AppointmentController@addService' => ['appointment.add_service', 'bookings', 'Update', Appointment::class, "Added a service to[ {subject}'s] booking"],
        'AppointmentController@addPackage' => ['appointment.add_package', 'bookings', 'Update', Appointment::class, "Added a package to[ {subject}'s] visit"],
        'AppointmentController@removeService' => ['appointment.remove_service', 'bookings', 'Delete', AppointmentServiceItem::class, 'Removed[ {subject}] from a booking'],
        'AppointmentController@voidService' => ['appointment.void_service', 'bookings', 'Cancel', AppointmentServiceItem::class, 'Voided[ {subject}]'],
        'AppointmentController@proceedToBilling' => ['appointment.to_billing', 'bookings', 'Complete', Appointment::class, "Sent[ {subject}'s] visit to billing"],
        'AppointmentController@callQueue' => ['queue.call', 'bookings', 'Update', Appointment::class, 'Called[ {subject}] from the queue'],
        'AppointmentController@recallQueue' => ['queue.recall', 'bookings', 'Update', Appointment::class, 'Called[ {subject}] again'],
        'AppointmentController@skipQueue' => ['queue.skip', 'bookings', 'Update', Appointment::class, 'Skipped[ {subject}] in the queue'],
        'AppointmentServiceController@addAdditionalService' => ['appointment.add_service', 'bookings', 'Update', Appointment::class, "Added a service to[ {subject}'s] visit"],
        'AppointmentServiceController@addAddon' => ['appointment.add_addon', 'bookings', 'Update', AppointmentServiceItem::class, 'Added an add-on to[ {subject}]'],
        'AppointmentServiceController@assignTherapist' => ['service.assign', 'bookings', 'Assign', TherapistAssignment::class, 'Assigned[ {subject}]'],
        'AppointmentServiceController@cancelAssignment' => ['service.unassign', 'bookings', 'Unassign', TherapistAssignment::class, 'Took off[ {subject}]'],
        'AppointmentServiceController@startService' => ['service.start', 'bookings', 'Update', AppointmentServiceItem::class, 'Started[ {subject}]'],
        'AppointmentServiceController@extendService' => ['service.extend', 'bookings', 'Update', AppointmentServiceItem::class, 'Extended[ {subject}]'],
        'AppointmentServiceController@completeService' => ['service.complete', 'bookings', 'Complete', AppointmentServiceItem::class, 'Finished[ {subject}]'],
        'AppointmentServiceController@assignRoom' => ['service.facility', 'bookings', 'Assign', TherapistAssignment::class, 'Set the facility for[ {subject}]'],
        'AppointmentServiceController@clearRoom' => ['service.facility_clear', 'bookings', 'Unassign', TherapistAssignment::class, 'Cleared the facility for[ {subject}]'],

        // ── Billing ──────────────────────────────────────────────────────
        'PaymentController@store' => ['payment.take', 'billing', 'Create', Payment::class, 'Took a payment[ of {amount}][ from {subject}]'],
        'PaymentController@void' => ['payment.void', 'billing', 'Cancel', Payment::class, 'Voided a payment[ of {amount}][ from {subject}]'],
        'BillingController@refund' => ['billing.refund', 'billing', 'Refund', Billing::class, 'Refunded[ {amount}][ to {subject}]'],
        'BillingController@discount' => ['billing.discount', 'billing', 'Update', Billing::class, "Gave a discount on[ {subject}'s] bill"],
        'BillingController@programDiscount' => ['billing.program_discount', 'billing', 'Update', Billing::class, "Applied a program discount to[ {subject}'s] bill"],

        // ── Clients and programs ─────────────────────────────────────────
        'ClientController@store' => ['client.create', 'clients', 'Create', Client::class, 'Added client[ {subject}]'],
        'ClientController@update' => ['client.update', 'clients', 'Update', Client::class, 'Edited client[ {subject}]'],
        'ClientController@setPreferredTherapist' => ['client.preferred_therapist', 'clients', 'Update', Client::class, "Set[ {subject}'s] preferred therapist"],
        'CustomerProgramController@redeem' => ['client.redeem', 'clients', 'Redeem', Client::class, 'Redeemed a reward[ for {subject}]'],
        'CustomerProgramController@sellMembership' => ['membership.sell', 'clients', 'Create', ClientMembership::class, 'Sold[ {subject}]'],
        'CustomerProgramController@renewMembership' => ['membership.renew', 'clients', 'Update', ClientMembership::class, 'Renewed[ {subject}]'],
        'CustomerProgramController@cancelMembership' => ['membership.cancel', 'clients', 'Cancel', ClientMembership::class, 'Cancelled[ {subject}]'],
        'CustomerProgramController@store' => ['program.create', 'clients', 'Create', CustomerProgram::class, 'Created program[ {subject}]'],
        'CustomerProgramController@update' => ['program.update', 'clients', 'Update', CustomerProgram::class, 'Edited program[ {subject}]'],
        'CustomerProgramController@destroy' => ['program.delete', 'clients', 'Delete', CustomerProgram::class, 'Removed program[ {subject}]'],
        'CustomerProgramController@module' => ['program.module', 'clients', 'Update', null, 'Switched Customer Programs[ {state}]'],
        'CustomerProgramController@branch' => ['program.branch', 'clients', 'Update', CustomerProgram::class, 'Changed where program[ {subject}] runs'],
        'ProgramRequestController@store' => ['program_request.create', 'clients', 'Submit', ProgramRequest::class, 'Asked for program[ {subject}]'],
        'ProgramRequestController@destroy' => ['program_request.withdraw', 'clients', 'Delete', ProgramRequest::class, 'Withdrew the request for program[ {subject}]'],
        'ProgramRequestController@approve' => ['program_request.approve', 'clients', 'Approve', ProgramRequest::class, 'Approved the request for program[ {subject}]'],
        'ProgramRequestController@decline' => ['program_request.decline', 'clients', 'Reject', ProgramRequest::class, 'Declined the request for program[ {subject}]'],

        // ── Staff and attendance ─────────────────────────────────────────
        'StaffController@store' => ['staff.create', 'staff', 'Create', Staff::class, 'Added employee[ {subject}]'],
        'StaffController@update' => ['staff.update', 'staff', 'Update', Staff::class, 'Edited employee[ {subject}]'],
        'StaffController@destroy' => ['staff.delete', 'staff', 'Delete', Staff::class, 'Removed employee[ {subject}]'],
        'StaffController@updateServices' => ['staff.services', 'staff', 'Update', Staff::class, 'Changed the services[ {subject}] does'],
        'StaffScheduleController@update' => ['staff.schedule', 'staff', 'Update', Staff::class, "Changed[ {subject}'s] schedule"],
        'StaffScheduleController@approveRequest' => ['schedule_request.approve', 'staff', 'Approve', StaffScheduleChangeRequest::class, "Approved[ {subject}'s] schedule change"],
        'StaffScheduleController@rejectRequest' => ['schedule_request.decline', 'staff', 'Reject', StaffScheduleChangeRequest::class, "Declined[ {subject}'s] schedule change"],
        'StaffScheduleController@withdrawRequest' => ['schedule_request.withdraw', 'staff', 'Delete', StaffScheduleChangeRequest::class, "Withdrew[ {subject}'s] schedule change"],
        'StaffTransferController@approve' => ['transfer.approve', 'staff', 'Approve', StaffTransferRequest::class, 'Approved the transfer of[ {subject}]'],
        'StaffTransferController@decline' => ['transfer.decline', 'staff', 'Reject', StaffTransferRequest::class, 'Declined the transfer of[ {subject}]'],
        'StaffTransferController@withdraw' => ['transfer.withdraw', 'staff', 'Delete', StaffTransferRequest::class, 'Withdrew the transfer of[ {subject}]'],
        'CommissionPlanController@store' => ['commission_plan.create', 'staff', 'Create', CommissionPlan::class, 'Created commission plan[ {subject}]'],
        'CommissionPlanController@update' => ['commission_plan.update', 'staff', 'Update', CommissionPlan::class, 'Edited commission plan[ {subject}]'],
        'CommissionPlanController@destroy' => ['commission_plan.delete', 'staff', 'Delete', CommissionPlan::class, 'Removed commission plan[ {subject}]'],
        'AttendanceController@store' => ['attendance.record', 'staff', 'Create', Attendance::class, 'Recorded attendance[ for {subject}]'],
        'AttendanceController@update' => ['attendance.update', 'staff', 'Update', Attendance::class, 'Edited the attendance[ of {subject}]'],
        'AttendanceController@destroy' => ['attendance.delete', 'staff', 'Delete', Attendance::class, 'Removed an attendance record[ of {subject}]'],
        'AttendanceController@bulkMarkPresent' => ['attendance.mark_all', 'staff', 'Check In', null, 'Marked everyone present[ ({count})]'],
        'FrontOfficeAttendanceController@checkIn' => ['attendance.check_in', 'staff', 'Check In', Attendance::class, 'Checked in[ {subject}] for work'],
        'FrontOfficeAttendanceController@checkOut' => ['attendance.check_out', 'staff', 'Check Out', Attendance::class, 'Checked out[ {subject}]'],
        'FrontOfficeAttendanceController@leave' => ['attendance.leave', 'staff', 'Update', Attendance::class, 'Put[ {subject}] on leave'],

        // ── Services and prices ──────────────────────────────────────────
        'ServiceController@store' => ['service.create', 'services', 'Create', Service::class, 'Added service[ {subject}]'],
        'ServiceController@update' => ['service.update', 'services', 'Update', Service::class, 'Edited service[ {subject}]'],
        'ServiceController@destroy' => ['service.delete', 'services', 'Delete', Service::class, 'Removed service[ {subject}]'],
        'ServiceController@updateVariantBranches' => ['service.branches', 'services', 'Update', ServiceVariant::class, 'Changed where[ {subject}] is offered'],
        'PackageController@store' => ['package.create', 'services', 'Create', Package::class, 'Added package[ {subject}]'],
        'PackageController@update' => ['package.update', 'services', 'Update', Package::class, 'Edited package[ {subject}]'],
        'PackageController@destroy' => ['package.delete', 'services', 'Delete', Package::class, 'Removed package[ {subject}]'],
        'PackageController@updateBranches' => ['package.branches', 'services', 'Update', Package::class, 'Changed where[ {subject}] is offered'],
        'AddonController@store' => ['addon.create', 'services', 'Create', Addon::class, 'Added add-on[ {subject}]'],
        'AddonController@update' => ['addon.update', 'services', 'Update', Addon::class, 'Edited add-on[ {subject}]'],
        'AddonController@destroy' => ['addon.delete', 'services', 'Delete', Addon::class, 'Removed add-on[ {subject}]'],
        'BranchOfferingController@update' => ['branch.offerings', 'services', 'Update', SpaBranch::class, "Changed[ {subject}'s] services and prices[ ({count})]"],
        'CatalogRequestController@store' => ['catalog_request.create', 'services', 'Submit', CatalogRequest::class, 'Asked for[ {subject}] to be added'],
        'CatalogRequestController@destroy' => ['catalog_request.withdraw', 'services', 'Delete', CatalogRequest::class, 'Withdrew the request for[ {subject}]'],
        'CatalogRequestController@added' => ['catalog_request.added', 'services', 'Approve', CatalogRequest::class, 'Added the requested[ {subject}]'],
        'CatalogRequestController@decline' => ['catalog_request.decline', 'services', 'Reject', CatalogRequest::class, 'Declined the request for[ {subject}]'],
        'FacilityController@store' => ['facility.create', 'services', 'Create', Facility::class, 'Added facility[ {subject}]'],
        'FacilityController@update' => ['facility.update', 'services', 'Update', Facility::class, 'Edited facility[ {subject}]'],
        'FacilityController@destroy' => ['facility.delete', 'services', 'Delete', Facility::class, 'Removed facility[ {subject}]'],

        // ── Branch and business settings ─────────────────────────────────
        'BranchScheduleController@store' => ['branch.hours', 'settings', 'Update', null, 'Changed the opening hours'],
        'BranchScheduleController@update' => ['branch.hours', 'settings', 'Update', null, 'Changed the opening hours'],
        'BranchScheduleController@destroy' => ['branch.hours', 'settings', 'Update', null, 'Changed the opening hours'],
        'BranchScheduleController@saveWeek' => ['branch.hours', 'settings', 'Update', null, 'Changed the opening hours'],
        'BranchClosureController@store' => ['branch.closure_create', 'settings', 'Create', BranchClosure::class, 'Closed the branch[ on {subject}]'],
        'BranchClosureController@update' => ['branch.closure_update', 'settings', 'Update', BranchClosure::class, 'Changed the closure[ on {subject}]'],
        'BranchClosureController@destroy' => ['branch.closure_delete', 'settings', 'Delete', BranchClosure::class, 'Removed the closure[ on {subject}]'],
        'BranchSettingsController@updateBookingPolicy' => ['branch.booking_policy', 'settings', 'Update', null, 'Changed the booking rules'],
        'BranchSettingsController@updateMarketplace' => ['branch.marketplace', 'settings', 'Update', null, 'Changed the marketplace listing'],
        'BranchSettingsController@uploadPhotos' => ['branch.photos_add', 'settings', 'Upload', null, 'Added photos'],
        'BranchSettingsController@reorderPhotos' => ['branch.photos_order', 'settings', 'Update', null, 'Reordered the photos'],
        'BranchSettingsController@deletePhoto' => ['branch.photo_delete', 'settings', 'Delete', null, 'Removed a photo'],
        'BranchSettingsController@setCover' => ['branch.photo_cover', 'settings', 'Update', null, 'Changed the cover photo'],
        'SpaBranchController@store' => ['branch.create', 'settings', 'Create', SpaBranch::class, 'Added branch[ {subject}]'],
        'SpaBranchController@update' => ['branch.update', 'settings', 'Update', SpaBranch::class, 'Edited branch[ {subject}]'],
        'SpaBranchController@destroy' => ['branch.delete', 'settings', 'Delete', SpaBranch::class, 'Removed branch[ {subject}]'],
        'SpaBranchController@bringBack' => ['branch.restore', 'settings', 'Restore', SpaBranch::class, 'Brought back branch[ {subject}]'],
        'SpaBranchController@saveLocation' => ['branch.location', 'settings', 'Update', SpaBranch::class, 'Set the location of[ {subject}]'],
        'SpaBranchController@savePermit' => ['branch.permit', 'settings', 'Upload', SpaBranch::class, 'Saved the permit of[ {subject}]'],
        'SpaBranchController@submit' => ['branch.submit', 'settings', 'Submit', SpaBranch::class, 'Sent[ {subject}] for approval'],
        'BusinessSettingsController@updateBookingDefaults' => ['business.booking_defaults', 'settings', 'Update', null, 'Changed the booking defaults'],
        'BusinessSettingsController@updateIdentity' => ['business.identity', 'settings', 'Update', null, 'Changed the business name or logo'],
        'BusinessSettingsController@updateLegal' => ['business.legal', 'settings', 'Update', null, 'Changed the legal details'],
        'BusinessSettingsController@updateNotifications' => ['business.notifications', 'settings', 'Update', null, 'Changed the notification settings'],
        'BusinessSettingsController@updatePayments' => ['business.payments', 'settings', 'Update', null, 'Changed the payment settings'],
        'BusinessSettingsController@updateStaffPolicy' => ['business.staff_policy', 'settings', 'Update', null, 'Changed the staff policies'],
        'FrontOfficeLookupController@replaceScannerLink' => ['branch.scanner_link', 'settings', 'Replace', null, 'Replaced the attendance scanner link'],
        'OwnerVerificationController@saveBusiness' => ['verification.business', 'settings', 'Upload', null, 'Saved the business verification documents'],
        'OwnerVerificationController@saveBusinessDetails' => ['verification.business_details', 'settings', 'Update', null, 'Saved the business verification details'],
        'OwnerVerificationController@saveIdentity' => ['verification.identity', 'settings', 'Upload', null, 'Saved the identity documents'],
        'OwnerVerificationController@saveFaceScan' => ['verification.face', 'settings', 'Upload', null, 'Saved the face scan'],
        'OwnerVerificationController@savePersonalDetails' => ['verification.personal', 'settings', 'Update', null, 'Saved the personal details'],
        'OwnerVerificationController@submit' => ['verification.submit', 'settings', 'Submit', null, 'Sent the verification for review'],
        'SubscriptionController@store' => ['subscription.create', 'settings', 'Create', null, 'Subscribed to a plan'],
        'SubscriptionController@startTrial' => ['subscription.trial', 'settings', 'Activate', null, 'Started the free trial'],
        'SubscriptionController@changePlan' => ['subscription.change', 'settings', 'Update', null, 'Changed the plan'],
        'SubscriptionController@cancelScheduledPlanChange' => ['subscription.change_cancel', 'settings', 'Cancel', null, 'Cancelled the planned plan change'],
        'SubscriptionController@respondToPlanChange' => ['subscription.change_answer', 'settings', 'Update', null, 'Answered a plan change'],
        'CheckoutController@start' => ['subscription.checkout', 'settings', 'Create', null, 'Started a plan payment'],
        'CheckoutController@setAutoRenew' => ['subscription.auto_renew', 'settings', 'Update', null, 'Turned auto-renew[ {state}]'],
        'CheckoutController@removeMethod' => ['subscription.method_remove', 'settings', 'Delete', null, 'Removed a saved payment method'],
        'CheckoutController@makeDefaultMethod' => ['subscription.method_default', 'settings', 'Update', null, 'Changed the default payment method'],

        // ── Logins ───────────────────────────────────────────────────────
        'AccountController@store' => ['account.create', 'accounts', 'Create', User::class, 'Created login[ {subject}]'],
        'AccountController@update' => ['account.update', 'accounts', 'Update', User::class, 'Changed login[ {subject}]'],
        'AccountController@destroy' => ['account.delete', 'accounts', 'Deactivate', User::class, 'Removed login[ {subject}]'],
        'AccountController@bringBack' => ['account.restore', 'accounts', 'Activate', User::class, 'Brought back login[ {subject}]'],
        'SecurityController@changePassword' => ['account.password', 'accounts', 'Change Password', null, 'Changed their own password'],
    ];

    // The records a sentence is about that belong to no one branch: the
    // catalog, the business's plans and programs, logins.
    public const BUSINESS_WIDE = [
        Service::class, ServiceVariant::class, Package::class, Addon::class,
        CommissionPlan::class, CustomerProgram::class, User::class,
    ];

    // Never written to the log, whatever the model.
    private const SECRET = '/(password|token|secret|two_factor|otp|recovery|api_key|scanner)/i';

    // Book-keeping columns that say nothing to a reader.
    private const NOISE = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    /**
     * The entry for a route, or null when it isn't logged.
     *
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: string}|null
     */
    public static function entry(string $action): ?array
    {
        if (in_array($action, self::SKIPPED, true)) {
            return null;
        }

        return self::MAP[$action] ?? self::fallback($action);
    }

    /**
     * A route nobody listed: "StaffWidgetController@archive" → "Staff widget:
     * archive". Filed under settings, about no particular record.
     */
    public static function fallback(string $action): array
    {
        [$controller, $method] = array_pad(explode('@', $action, 2), 2, 'handle');
        $thing = trim(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', preg_replace('/Controller$/', '', $controller))));
        $doing = trim(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $method)));
        $verb = ['store' => 'Create', 'update' => 'Update', 'destroy' => 'Delete'][$method] ?? 'Update';

        return [str_replace(' ', '_', $thing) . '.' . str_replace(' ', '_', $doing), 'settings', $verb, null, ucfirst($thing) . ': ' . $doing];
    }

    /**
     * Fills a sentence. Each [part] whose placeholders are all known stays
     * (brackets dropped); one naming anything unknown is left out.
     *
     * @param  array<string, string|null>  $values
     */
    public static function fill(string $sentence, array $values): string
    {
        $out = preg_replace_callback('/\[([^\]]*)\]/', function (array $m) use ($values) {
            $part = $m[1];
            preg_match_all('/\{(\w+)\}/', $part, $names);
            foreach ($names[1] as $name) {
                if (($values[$name] ?? '') === '' || $values[$name] === null) {
                    return '';
                }
            }

            return self::put($part, $values);
        }, $sentence);

        return trim(preg_replace('/\s{2,}/', ' ', self::put($out, $values)));
    }

    private static function put(string $text, array $values): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn (array $m) => (string) ($values[$m[1]] ?? ''), $text);
    }

    /**
     * What a reader may see of a record's values: no secrets, no
     * book-keeping, nothing long. Lists and objects are kept short.
     */
    public static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            // Ids and "who saved it" columns read as numbers; the row already
            // says who did it and what it was about.
            if (in_array($key, self::NOISE, true) || preg_match(self::SECRET, (string) $key)
                || preg_match('/(_id|_by|_uuid)$/', (string) $key)) {
                continue;
            }
            if (is_string($value) && strlen($value) > 300) {
                $value = substr($value, 0, 297) . '…';
            }
            if (is_array($value)) {
                $value = self::clean($value);
            }
            $out[$key] = $value;
            if (count($out) >= 30) {
                break;
            }
        }

        return $out;
    }

    public static function peso($amount): ?string
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return '₱' . number_format((float) $amount, fmod((float) $amount, 1.0) == 0.0 ? 0 : 2);
    }

    /** What a record is called in a sentence: "Ana Cruz", "Swedish Massage 1h 30min for Ana Cruz". */
    public static function label(?Model $model): ?string
    {
        if (! $model) {
            return null;
        }

        $person = fn ($p) => $p ? trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) ?: null : null;

        $label = match (true) {
            $model instanceof Appointment => $person($model->client),
            $model instanceof Client, $model instanceof Staff => $person($model),
            $model instanceof Billing => $person($model->appointment?->client) ?? $model->billing_number,
            $model instanceof Payment => $person($model->billing?->appointment?->client) ?? $model->billing?->billing_number,
            $model instanceof Attendance => $person($model->staff),
            $model instanceof AppointmentServiceItem => self::serviceFor($model->displayName(), $person($model->appointment?->client)),
            $model instanceof AppointmentPackage => self::serviceFor($model->displayName(), $person($model->appointment?->client)),
            $model instanceof TherapistAssignment => self::assignmentLabel($model, $person),
            $model instanceof ClientMembership => trim(($model->program_name ?? 'a membership') . (($c = $person($model->client)) ? " for {$c}" : '')),
            $model instanceof ServiceVariant => trim(($model->service?->name ?? 'a service') . ' ' . self::minutes($model->duration_minutes)),
            $model instanceof SpaBranch => $model->branch_name,
            $model instanceof StaffTransferRequest => trim(($person($model->staff) ?? 'an employee') . ($model->to_branch_name ? " to {$model->to_branch_name}" : '')),
            $model instanceof StaffScheduleChangeRequest => $person($model->staff),
            $model instanceof BranchClosure => $model->starts_at ? Carbon::parse($model->starts_at)->format('M j, Y') : null,
            $model instanceof User => $model->displayName(),
            default => $model->getAttribute('name') ?? $model->getAttribute('title'),
        };

        return $label !== null && $label !== '' ? mb_substr((string) $label, 0, 160) : null;
    }

    private static function serviceFor(?string $service, ?string $client): ?string
    {
        if (! $service) {
            return $client;
        }

        return $client ? "{$service} for {$client}" : $service;
    }

    private static function assignmentLabel(TherapistAssignment $a, callable $person): ?string
    {
        $line = $a->appointmentService;
        $service = self::serviceFor($line?->displayName(), $person($line?->appointment?->client));
        $who = $a->staff_name ?: $person($a->staff);

        return $who ? ($service ? "{$who} on {$service}" : $who) : $service;
    }

    private static function minutes($minutes): string
    {
        $m = (int) $minutes;
        if ($m <= 0) {
            return '';
        }
        $h = intdiv($m, 60);
        $r = $m % 60;

        return trim(($h ? "{$h}h" : '') . ($r ? " {$r}min" : ''));
    }

    /** The branch a record belongs to, when it belongs to one. */
    public static function branchIdOf(?Model $model): ?int
    {
        if (! $model) {
            return null;
        }
        if ($model instanceof SpaBranch) {
            return $model->id;
        }
        if ($id = $model->getAttribute('spa_branch_id')) {
            return (int) $id;
        }

        $id = match (true) {
            $model instanceof Attendance => $model->staff?->spa_branch_id,
            $model instanceof AppointmentServiceItem, $model instanceof AppointmentPackage => $model->appointment?->spa_branch_id,
            $model instanceof TherapistAssignment => $model->appointmentService?->appointment?->spa_branch_id,
            $model instanceof StaffTransferRequest => $model->from_branch_id,
            default => null,
        };

        return $id ? (int) $id : null;
    }

    /** "Oct 21, 3:00 PM" from an appointment's date and time. */
    public static function when(?Model $appointment): ?string
    {
        if (! $appointment instanceof Appointment || ! $appointment->appointment_date) {
            return null;
        }
        $date = Carbon::parse($appointment->appointment_date)->format('M j');
        $time = $appointment->appointment_time ? Carbon::parse($appointment->appointment_time)->format('g:i A') : null;

        return $time ? "{$date}, {$time}" : $date;
    }
}
