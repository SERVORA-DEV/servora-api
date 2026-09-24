<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\Settings\BookingDefaultsSettingsRequest;
use App\Http\Requests\Business\Settings\BusinessIdentityRequest;
use App\Http\Requests\Business\Settings\BusinessLegalRequest;
use App\Http\Requests\Business\Settings\NotificationSettingsRequest;
use App\Http\Requests\Business\Settings\PaymentSettingsRequest;
use App\Http\Requests\Business\Settings\StaffPolicySettingsRequest;
use App\Service\Business\BusinessSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BusinessSettingsController extends Controller
{
    public function __construct(private BusinessSettingsService $businessSettingsService) {}

    public function show(Request $request)
    {
        return $this->businessSettingsService->show($request->user());
    }

    public function updateIdentity(BusinessIdentityRequest $request)
    {
        return $this->businessSettingsService->updateIdentity(
            $request->user(),
            Arr::except($request->validated(), ['logo', 'remove_logo']),
            $request->file('logo'),
            $request->boolean('remove_logo'),
            $request,
        );
    }

    public function updateLegal(BusinessLegalRequest $request)
    {
        return $this->businessSettingsService->updateLegal($request->user(), $request->validated(), $request);
    }

    public function updatePayments(PaymentSettingsRequest $request)
    {
        return $this->businessSettingsService->updateSection($request->user(), 'payments', $request->validated());
    }

    public function updateStaffPolicy(StaffPolicySettingsRequest $request)
    {
        return $this->businessSettingsService->updateSection($request->user(), 'staff_policy', $request->validated());
    }

    public function updateBookingDefaults(BookingDefaultsSettingsRequest $request)
    {
        return $this->businessSettingsService->updateSection($request->user(), 'booking_defaults', $request->validated());
    }

    public function updateNotifications(NotificationSettingsRequest $request)
    {
        return $this->businessSettingsService->updateSection($request->user(), 'notifications', $request->validated());
    }
}
