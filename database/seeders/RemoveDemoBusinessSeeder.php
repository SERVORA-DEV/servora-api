<?php

namespace Database\Seeders;

use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Takes out everything DemoBusinessSeeder created for "Lotus Haven Spa", so
// the demo can be reset (run DemoBusinessSeeder again afterwards). Refuses
// once the demo has real activity — clients or appointments — rather than
// deleting it. Service templates are kept.
class RemoveDemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', DemoBusinessSeeder::OWNER_EMAIL)->where('role', 'business_owner')->first();
        $business = $owner ? SpaBusiness::withTrashed()->where('owner_id', $owner->id)->first() : null;
        if (! $business) {
            $this->command->info('No demo business to remove.');

            return;
        }

        $branchIds = DB::table('spa_branches')->where('spa_business_id', $business->id)->pluck('id');
        $clients = DB::table('clients')->where('spa_business_id', $business->id)->count();
        $appointments = DB::table('appointments')->whereIn('spa_branch_id', $branchIds)->count();
        if ($clients || $appointments) {
            $this->command->error("Not removed: {$business->business_name} has {$clients} clients and {$appointments} appointments.");

            return;
        }

        DB::transaction(function () use ($owner, $business, $branchIds) {
            $staffIds = DB::table('staff')->whereIn('spa_branch_id', $branchIds)->pluck('id');
            $userIds = DB::table('staff')->whereIn('id', $staffIds)->whereNotNull('user_id')->pluck('user_id')->push($owner->id);
            $serviceIds = DB::table('services')->where('spa_business_id', $business->id)->pluck('id');
            $variantIds = DB::table('service_variants')->whereIn('service_id', $serviceIds)->pluck('id');
            $packageIds = DB::table('packages')->where('spa_business_id', $business->id)->pluck('id');
            $facilityIds = DB::table('facilities')->whereIn('spa_branch_id', $branchIds)->pluck('id');
            $programIds = DB::table('customer_programs')->where('spa_business_id', $business->id)->pluck('id');

            DB::table('customer_program_items')->whereIn('membership_program_id', $programIds)->orWhereIn('item_program_id', $programIds)->delete();
            DB::table('customer_program_branch')->whereIn('customer_program_id', $programIds)->delete();
            DB::table('customer_programs')->whereIn('id', $programIds)->delete();

            DB::table('facility_services')->whereIn('facility_id', $facilityIds)->delete();
            DB::table('facilities')->whereIn('id', $facilityIds)->delete();

            DB::table('branch_packages')->whereIn('package_id', $packageIds)->delete();
            DB::table('package_services')->whereIn('package_id', $packageIds)->delete();
            DB::table('packages')->whereIn('id', $packageIds)->delete();

            DB::table('staff_services')->whereIn('staff_id', $staffIds)->orWhereIn('service_id', $serviceIds)->delete();
            DB::table('branch_services')->whereIn('service_variant_id', $variantIds)->delete();
            DB::table('service_variants')->whereIn('id', $variantIds)->delete();
            DB::table('services')->whereIn('id', $serviceIds)->delete();

            DB::table('staff_schedules')->whereIn('staff_id', $staffIds)->delete();
            DB::table('attendances')->whereIn('staff_id', $staffIds)->delete();
            DB::table('staff')->whereIn('id', $staffIds)->delete();

            DB::table('branch_schedules')->whereIn('spa_branch_id', $branchIds)->delete();
            DB::table('spa_branch_photos')->whereIn('spa_branch_id', $branchIds)->delete();
            DB::table('spa_branches')->whereIn('id', $branchIds)->delete();

            DB::table('spa_business_settings')->where('spa_business_id', $business->id)->delete();
            DB::table('subscriptions')->where('spa_business_id', $business->id)->delete();
            DB::table('spa_businesses')->where('id', $business->id)->delete();

            DB::table('notifications')->whereIn('user_id', $userIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('user_permissions')->whereIn('user_id', $userIds)->delete();
            DB::table('audit_logs')->whereIn('user_id', $userIds)->delete();
            DB::table('owner_identity_verifications')->where('user_id', $owner->id)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        });

        $this->command->info("Removed {$business->business_name}.");
    }
}
