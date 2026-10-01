<?php

namespace Tests\Concerns;

use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Service\Client\BookingPolicy;

// Gives a test branch what MarketplaceReadiness needs before clients can
// find or book it: opening hours, an active therapist and (optionally) a
// service. Only fills what's missing, so a test's own therapist/services
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
    protected function makeBookable(SpaBranch $branch, bool $withService = false): void
    {
        BranchSchedule::firstOrCreate(
            ['spa_branch_id' => $branch->id, 'day_of_week' => 'Sunday'],
            ['opening_time' => '09:00:00', 'closing_time' => '21:00:00', 'is_closed' => false],
        );

        $hasTherapist = Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')->where('status', 'active')->exists();
        if (! $hasTherapist) {
            Staff::factory()->create(['spa_branch_id' => $branch->id, 'role' => 'therapist', 'status' => 'active']);
        }

        if ($withService) {
            BranchService::factory()->create([
                'spa_branch_id' => $branch->id,
                'service_variant_id' => ServiceVariant::factory()->create([
                    'service_id' => Service::factory()->create(['spa_business_id' => $branch->spa_business_id])->id,
                ])->id,
            ]);
        }

        $settings = SpaBusinessSetting::firstOrCreate(['spa_business_id' => $branch->spa_business_id]);
        $settings->update(['booking_defaults' => array_merge($settings->booking_defaults ?? [], ['max_advance_booking_days' => 0])]);
        BookingPolicy::flush();
    }
}
