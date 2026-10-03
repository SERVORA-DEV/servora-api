<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The free trial gets a plan of its own (category "Trial") instead of
// borrowing a paid tier — see config/trial.php and
// SubscriptionService::startTrial.
return new class extends Migration
{
    private const CATEGORIES = ['Basic', 'Premium', 'Enterprise'];

    public function up(): void
    {
        $this->allowCategories([...self::CATEGORIES, 'Trial']);

        $trialPlanId = DB::table('subscription_plans')
            ->where('category', 'Trial')->where('is_active', true)->whereNull('deleted_at')
            ->value('id');

        if (! $trialPlanId) {
            // Starts out with the current Basic plan's limits and features.
            $basic = DB::table('subscription_plans')
                ->where('category', 'Basic')->where('is_active', true)->whereNull('deleted_at')
                ->orderByDesc('id')->first();

            $trialPlanId = DB::table('subscription_plans')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'category' => 'Trial',
                'name' => 'Free Trial',
                'description' => 'Try SERVORA with your own spa before you choose a plan.',
                'monthly_price' => 0,
                'yearly_price' => null,
                'billing_cycle' => 'Monthly',
                'max_branches' => $basic->max_branches ?? 1,
                'max_user_accounts' => $basic->max_user_accounts ?? 2,
                'package_access' => $basic->package_access ?? false,
                'reward_access' => $basic->reward_access ?? false,
                'review_access' => $basic->review_access ?? true,
                'report_access' => $basic->report_access ?? true,
                'report_export' => $basic->report_export ?? false,
                'mobile_app_access' => $basic->mobile_app_access ?? false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Trials started while the trial still borrowed a paid tier.
        DB::table('subscriptions')->where('is_trial', true)->update(['subscription_plan_id' => $trialPlanId]);

        // Written with the query builder, so the model events that normally
        // refresh the cached plan list and each business's plan never fired.
        \App\Support\AppCache::bump('plans');
        \App\Support\AppCache::bump('plans-public');
        DB::table('subscriptions')->where('is_trial', true)->pluck('spa_business_id')
            ->each(fn ($businessId) => \App\Support\AppCache::forgetSubscription((int) $businessId));
    }

    public function down(): void
    {
        // Trial subscriptions point at the Trial plan; they go back to Basic
        // so the category can be removed again.
        $basicId = DB::table('subscription_plans')->where('category', 'Basic')->orderByDesc('is_active')->orderByDesc('id')->value('id');
        $trialIds = DB::table('subscription_plans')->where('category', 'Trial')->pluck('id');

        if ($basicId) {
            DB::table('subscriptions')->whereIn('subscription_plan_id', $trialIds)->update(['subscription_plan_id' => $basicId]);
        }
        DB::table('subscription_plans')->whereIn('id', $trialIds)->delete();

        $this->allowCategories(self::CATEGORIES);
    }

    // The enum column is a varchar with a CHECK constraint on PostgreSQL.
    private function allowCategories(array $categories): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($c) => "'{$c}'", $categories));
        DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_category_check');
        DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_category_check CHECK (category::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
