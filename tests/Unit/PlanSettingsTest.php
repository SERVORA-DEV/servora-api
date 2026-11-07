<?php

namespace Tests\Unit;

use App\Http\Requests\SubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

// Reviews come with every plan and app bookings have no monthly limit, so neither is a plan
// setting. No database.
//   php artisan test --filter=PlanSettingsTest
class PlanSettingsTest extends TestCase
{
    public function test_a_plan_no_longer_takes_either_setting(): void
    {
        $rules = (new SubscriptionPlanRequest())->rules();

        $this->assertArrayNotHasKey('review_access', $rules);
        $this->assertArrayNotHasKey('app_booking_limit', $rules);
        $this->assertNotContains('review_access', (new SubscriptionPlan())->getFillable());
        $this->assertNotContains('app_booking_limit', (new SubscriptionPlan())->getFillable());
    }

    public function test_a_plan_does_not_send_either_setting(): void
    {
        $plan = (new SubscriptionPlan())->forceFill(['name' => 'Basic', 'review_access' => false, 'app_booking_limit' => 30]);

        $this->assertArrayNotHasKey('review_access', $plan->toArray());
        $this->assertArrayNotHasKey('app_booking_limit', $plan->toArray());
    }

    // Paid plans are always sold monthly and yearly, so both prices are required whatever is sent.
    public function test_a_paid_plan_is_always_monthly_and_yearly(): void
    {
        $validate = function (array $input): \Illuminate\Validation\Validator {
            $request = SubscriptionPlanRequest::create('/', 'POST', $input);
            (fn () => $this->prepareForValidation())->call($request);

            return validator($request->all(), $request->rules());
        };
        $plan = ['category' => 'Basic', 'name' => 'Basic', 'max_branches' => 1, 'max_user_accounts' => 2];

        $monthlyOnly = $validate($plan + ['billing_cycle' => 'Monthly', 'monthly_price' => 499]);
        $this->assertSame('Both', $monthlyOnly->getData()['billing_cycle']);
        $this->assertTrue($monthlyOnly->errors()->has('yearly_price'));

        $both = $validate($plan + ['billing_cycle' => 'Monthly', 'monthly_price' => 499, 'yearly_price' => 4990]);
        $this->assertFalse($both->fails(), $both->errors()->toJson());

        // The Free Trial is never charged and keeps its own setting.
        $trial = $validate(['category' => 'Trial', 'name' => 'Free Trial', 'billing_cycle' => 'Monthly', 'monthly_price' => 0, 'max_branches' => 1, 'max_user_accounts' => 2]);
        $this->assertSame('Monthly', $trial->getData()['billing_cycle']);
        $this->assertFalse($trial->fails(), $trial->errors()->toJson());
    }

    public function test_no_route_checks_the_plan_for_reviews(): void
    {
        foreach (Router::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(is_string($middleware) && str_contains($middleware, 'review_access'), $route->uri() . ' still checks the plan for reviews');
            }
        }
    }
}
