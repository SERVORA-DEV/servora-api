<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Service\System\SubscriptionPlanService;
use Illuminate\Database\Seeder;

// The plans owners choose from (Plans page, "Compare plans"), and the free
// trial's own plan. Run by DatabaseSeeder, so `php artisan migrate:fresh --seed`
// sets them up.
//
// Safe to run again: each plan is saved through SubscriptionPlanService like
// the admin's own edits. Unchanged values change nothing; changed ones update
// the plan, or add a new version when owners are already on it (they keep the
// terms they bought).
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // The free trial (config/trial.php): every feature for 7 days, never sold.
            [
                'category' => 'Trial',
                'name' => 'Free Trial',
                'description' => 'Try SERVORA with your own spa before you choose a plan.',
                'trial_days' => 7,

                'monthly_price' => 0,
                'yearly_price' => null,
                'billing_cycle' => 'Monthly',

                'max_branches' => 1,
                'max_user_accounts' => 2,
                'extra_branch_price' => null,
                'extra_account_price' => null,

                'report_access' => true,
                'report_export' => true,
                'export_mode' => 'full',
                'export_days' => null,
                'mobile_app_access' => true,
                'package_access' => true,
                'reward_access' => true,

                'is_active' => true,
            ],

            [
                'category' => 'Basic',
                'name' => 'Basic',
                'description' => 'Perfect for small spa businesses getting started.',

                'monthly_price' => 599.00,
                'yearly_price' => 5990.00,
                'billing_cycle' => 'Both',

                'max_branches' => 1,
                'max_user_accounts' => 2,
                'extra_branch_price' => null,
                'extra_account_price' => null,

                'report_access' => true,
                'report_export' => true,     // PDF, Excel & CSV
                'export_mode' => 'full',
                'export_days' => null,       // any date range
                'mobile_app_access' => true, // client app listing
                'package_access' => false,
                'reward_access' => false,    // loyalty & memberships

                'is_active' => true,
            ],

            [
                'category' => 'Premium',
                'name' => 'Premium',
                'description' => 'Ideal for growing spa businesses with multiple staff.',

                'monthly_price' => 1299.00,
                'yearly_price' => 12990.00,
                'billing_cycle' => 'Both',

                'max_branches' => 3,
                'max_user_accounts' => 10,
                'extra_branch_price' => null,
                'extra_account_price' => null,

                'report_access' => true,
                'report_export' => true,
                'export_mode' => 'full',
                'export_days' => null,
                'mobile_app_access' => true,
                'package_access' => true,
                'reward_access' => true,

                'is_active' => true,
            ],

            [
                'category' => 'Enterprise',
                'name' => 'Enterprise',
                'description' => 'Best for large spa chains with unlimited expansion.',

                'monthly_price' => 3499.00,
                'yearly_price' => 34990.00,
                'billing_cycle' => 'Both',

                'max_branches' => 5,
                'max_user_accounts' => 20,
                'extra_branch_price' => 349.00,  // + ₱349 / month for each branch over 5
                'extra_account_price' => 49.00,  // + ₱49 / month for each account over 20

                'report_access' => true,
                'report_export' => true,
                'export_mode' => 'full',
                'export_days' => null,
                'mobile_app_access' => true,
                'package_access' => true,
                'reward_access' => true,

                'is_active' => true,
            ],
        ];

        $service = app(SubscriptionPlanService::class);

        foreach ($plans as $plan) {
            // The active plan of this category (older versions may sit beside it, archived).
            $current = SubscriptionPlan::where('category', $plan['category'])->where('is_active', true)->first();

            $current
                ? $service->updateSubscriptionPlan($current->uuid, $plan)
                : $service->createSubscriptionPlan($plan);
        }
    }
}
