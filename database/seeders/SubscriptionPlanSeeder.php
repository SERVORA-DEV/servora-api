<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SubscriptionPlan::insert([
            [
                'uuid' => Str::uuid(),

                'category' => 'Basic',
                'name' => 'Basic',
                'description' => 'Perfect for small spa businesses getting started.',

                'monthly_price' => 499.00,
                'yearly_price' => 4990.00,

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

                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'uuid' => Str::uuid(),

                'category' => 'Premium',
                'name' => 'Premium',
                'description' => 'Ideal for growing spa businesses with multiple staff.',

                'monthly_price' => 999.00,
                'yearly_price' => 9990.00,

                'billing_cycle' => 'Monthly',

                'max_branches' => 5,
                'max_user_accounts' => 20,

                'package_access' => true,
                'reward_access' => true,
                'review_access' => true,
                'report_access' => true,
                'report_export' => true,
                'mobile_app_access' => true,

                'is_active' => true,

                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'uuid' => Str::uuid(),

                'category' => 'Enterprise',
                'name' => 'Enterprise',
                'description' => 'Best for large spa chains with unlimited expansion.',

                'monthly_price' => 1999.00,
                'yearly_price' => 19990.00,

                'billing_cycle' => 'Monthly',

                'max_branches' => 9999,
                'max_user_accounts' => 9999,

                'package_access' => true,
                'reward_access' => true,
                'review_access' => true,
                'report_access' => true,
                'report_export' => true,
                'mobile_app_access' => true,

                'is_active' => true,

                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}