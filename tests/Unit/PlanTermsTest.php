<?php

namespace Tests\Unit;

use App\Models\SubscriptionPlan;
use App\Support\PlanTerms;
use Tests\TestCase;

class PlanTermsTest extends TestCase
{
    public function test_yearly_extras_do_not_inherit_the_base_plan_discount(): void
    {
        $plan = new SubscriptionPlan(['category' => 'Enterprise', 'max_branches' => 15, 'max_user_accounts' => 30, 'extra_branch_price' => 199, 'extra_account_price' => 49]);
        $extras = PlanTerms::extras($plan, ['extra_branches' => 2, 'extra_accounts' => 4]);
        $this->assertSame(594.0, PlanTerms::extraPrice($plan, 'Monthly', $extras));
        $this->assertSame(7128.0, PlanTerms::extraPrice($plan, 'Yearly', $extras));
        $effective = PlanTerms::effective($plan, $extras);
        $this->assertSame(17, $effective->max_branches);
        $this->assertSame(34, $effective->max_user_accounts);
        $this->assertSame(15, $plan->max_branches);
    }

    public function test_basic_cannot_buy_an_extra_branch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PlanTerms::extras(new SubscriptionPlan(['category' => 'Basic', 'extra_branch_price' => null]), ['extra_branches' => 1]);
    }

    public function test_formats_respect_both_feature_and_export_policy(): void
    {
        $plan = new SubscriptionPlan(['report_access' => true, 'report_export' => true, 'export_mode' => 'csv']);
        $this->assertSame(['csv'], PlanTerms::exportFormats($plan));
        $plan->report_access = false;
        $this->assertSame([], PlanTerms::exportFormats($plan));
        $plan->report_access = true;
        $plan->export_mode = null;
        $this->assertSame(['pdf', 'excel'], PlanTerms::exportFormats($plan));
    }
}
