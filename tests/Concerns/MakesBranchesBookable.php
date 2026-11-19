<?php

namespace Tests\Concerns;

use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\OwnerIdentityVerification;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Service\Client\BookingPolicy;
use App\Support\Platform\ListingPolicy;

// Gives a test branch what MarketplaceReadiness needs before clients can
// find or book it: a verified owner, a live plan with client-app listing, opening hours, an
// active therapist with shifts and (optionally) a service. Only fills what's missing, so a test's own therapist/services
// stay the ones it asserts on.
//
// Hours go on Sunday only — other weekdays have no row, which the booking
// checks treat as open (fallback hours), so tests stay free to add their
// own rows for the days they use.
//
// Also lifts the "book up to N days ahead" limit, since these tests book on
// fixed 2027 dates.
trait MakesBranchesBookable
{
    protected function verifyOwner(int $ownerId): void
    {
        OwnerIdentityVerification::updateOrCreate(['user_id' => $ownerId], [
            'id_type' => 'Drivers License', 'id_document_front_path' => 'test/front', 'id_document_back_path' => 'test/back',
            'face_scan_paths' => ['test/face'], 'liveness_sequence' => ['center', 'left', 'right'],
            'liveness_result' => 'Passed', 'status' => 'Verified',
        ]);
    }

    // $scheduleTherapists: give every active therapist who has no schedule at all a shift
    // during the branch's hours on every day (a therapist with no shift can't be booked —
    // BookingSlotService, MarketplaceReadiness). A test about schedules themselves passes
    // false and enters its own shifts.
    protected function makeBookable(SpaBranch $branch, bool $withService = false, bool $scheduleTherapists = true): void
    {
        // A bare factory business has no owner; a listed branch needs a verified one.
        $business = $branch->business;
        if (! $business->owner_id) {
            $business->update(['owner_id' => User::factory()->create(['role' => 'business_owner'])->id]);
        }
        $this->verifyOwner($business->owner_id);

        // Listed spas need a live plan that includes the client app (and packages, which a
        // plan without them hides from clients).
        if (! Subscription::where('spa_business_id', $business->id)->where('status', 'Active')->exists()) {
            $plan = SubscriptionPlan::create([
                'category' => 'Premium', 'name' => 'Premium', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
                'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
                'mobile_app_access' => true, 'package_access' => true,
            ]);
            Subscription::create([
                'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
                'starts_at' => now()->subDay(), 'expires_at' => now()->addYears(3), 'status' => 'Active',
            ]);
        }

        BranchSchedule::firstOrCreate(
            ['spa_branch_id' => $branch->id, 'day_of_week' => 'Sunday'],
            ['opening_time' => '09:00:00', 'closing_time' => '21:00:00', 'is_closed' => false],
        );

        $hasTherapist = Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')->where('status', 'active')->exists();
        if (! $hasTherapist) {
            Staff::factory()->create(['spa_branch_id' => $branch->id, 'role' => 'therapist', 'status' => 'active']);
        }

        if ($scheduleTherapists) {
            $unscheduled = Staff::where('spa_branch_id', $branch->id)->where('role', 'therapist')
                ->where('status', 'active')->whereDoesntHave('schedules')->pluck('id');
            foreach ($unscheduled as $staffId) {
                foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
                    StaffSchedule::create(['staff_id' => $staffId, 'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '21:00:00']);
                }
            }
        }

        if ($withService) {
            BranchService::factory()->create([
                'spa_branch_id' => $branch->id,
                'service_variant_id' => ServiceVariant::factory()->create([
                    'service_id' => Service::factory()->create(['spa_business_id' => $branch->spa_business_id])->id,
                ])->id,
            ]);
        }

        // The administrator's listing policy (cover photo, description,
        // highlights — see ListingPolicy) is its own subject, tested in
        // ListingPolicyVisibilityTest. Everywhere else every item is optional,
        // so a bare factory branch is listed on the fixed rules alone.
        SystemSetting::current()->update([
            'listing_policy' => array_fill_keys(array_keys(ListingPolicy::DEFAULTS), ListingPolicy::OPTIONAL),
        ]);

        $settings = SpaBusinessSetting::firstOrCreate(['spa_business_id' => $branch->spa_business_id]);
        $settings->update(['booking_defaults' => array_merge($settings->booking_defaults ?? [], ['max_advance_booking_days' => 0])]);
        BookingPolicy::flush();
    }
}
