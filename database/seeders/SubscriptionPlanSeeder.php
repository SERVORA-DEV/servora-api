<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Service\System\SubscriptionPlanService;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            // The free trial's own plan (config/trial.php): Basic's limits,
            // never sold.
            [
                'category' => 'Trial',
                'name' => 'Free Trial',
                'description' => 'Try SERVORA with your own spa before you choose a plan.',

                'monthly_price' => 0,
                'yearly_price' => null,

                'billing_cycle' => 'Monthly',

                'max_branches' => 1,
                'max_user_accounts' => 2,

                'package_access' => false,
                'reward_access' => false,
                'review_access' => true,
                'report_access' => true,
                'report_export' => false,
                'mobile_app_access' => false,

                'is_active' => true,
            ],

            [
                'category' => 'Basic',
                'name' => 'Basic',
                'description' => 'Perfect for small spa businesses getting started.',

                'monthly_price' => 499.00,
                'yearly_price' => 4990.00,

                'billing_cycle' => 'Monthly',

                'max_branches' => 1,
                'max_user_accounts' => 3,

                'package_access' => false,
                'reward_access' => false,
                'review_access' => true,
                'report_access' => true,
                'report_export' => false,
                'mobile_app_access' => false,

                'is_active' => true,
            ],

            [
                'category' => 'Premium',
                'name' => 'Premium',
                'description' => 'Ideal for growing spa businesses with multiple staff.',

                'monthly_price' => 999.00,
                'yearly_price' => 9990.00,

                'billing_cycle' => 'Monthly',

                'max_branches' => 3,
                'max_user_accounts' => 10,

                'package_access' => true,
                'reward_access' => true,
                'review_access' => true,
                'report_access' => true,
                'report_export' => true,
                'mobile_app_access' => true,

                'is_active' => true,
            ],

            [
                'category' => 'Enterprise',
                'name' => 'Enterprise',
                'description' => 'Best for large spa chains with unlimited expansion.',

                'monthly_price' => 1999.00,
                'yearly_price' => 19990.00,

                'billing_cycle' => 'Monthly',

                'max_branches' => 15,
                'max_user_accounts' => 30,

                'package_access' => true,
                'reward_access' => true,
                'review_access' => true,
                'report_access' => true,
                'report_export' => true,
                'mobile_app_access' => true,

                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            // Match on the *active* plan for this category, not just the
            // category alone — a category can have archived historical
            // versions sitting alongside its current active plan (see
            // SubscriptionPlanService::updateSubscriptionPlan()), so
            // matching on category only could silently reactivate or
            // overwrite the wrong row.
            if ($plan['category'] === 'Trial') {
                if (! SubscriptionPlan::where('category', 'Trial')->exists()) {
                    SubscriptionPlan::create($plan);
                }

                continue;
            }
            $basic = $plan['category'] === 'Basic';
            $plan = array_merge($plan, [
                'billing_cycle' => 'Both', 'mobile_app_access' => true, 'report_export' => true,
                'export_mode' => $basic ? 'csv' : 'full', 'export_days' => $basic ? 30 : null,
                'app_booking_limit' => $basic ? 30 : null,
                'extra_account_price' => 49, 'extra_branch_price' => $plan['category'] === 'Enterprise' ? 199 : null,
            ]);
            $current = SubscriptionPlan::where('category', $plan['category'])->where('is_active', true)->first();
            $service = app(SubscriptionPlanService::class);
            $current ? $service->updateSubscriptionPlan($current->uuid, $plan) : $service->createSubscriptionPlan($plan);
        }
    }
}
