<?php

namespace Tests\Feature;

use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The free trial (config/trial.php): one per business, on the dedicated Free
// Trial plan, never charged, and that plan can't be bought or switched to.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=FreeTrialTest
class FreeTrialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SubscriptionPlan $trialPlan;
    private SubscriptionPlan $basic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trial.enabled' => true, 'trial.days' => 14, 'trial.plan_category' => 'Trial']);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id, 'verification_status' => 'Verified']);
        $this->trialPlan = $this->plan('Free Trial', 'Trial', 0);
        $this->basic = $this->plan('Basic', 'Basic', 500);

        Sanctum::actingAs($this->owner);
    }

    private function plan(string $name, string $category, float $monthly): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'category' => $category, 'name' => $name, 'monthly_price' => $monthly, 'yearly_price' => $monthly ? $monthly * 10 : null,
            'billing_cycle' => 'Monthly', 'max_branches' => 1, 'max_user_accounts' => 2, 'is_active' => true,
        ]);
    }

    public function test_the_trial_is_offered_to_a_business_that_never_had_a_plan(): void
    {
        $this->getJson('/api/business/subscription')
            ->assertOk()
            ->assertJsonPath('has_subscription', false)
            ->assertJsonPath('trial.available', true)
            ->assertJsonPath('trial.days', 14)
            ->assertJsonPath('trial.plan_name', 'Free Trial');
    }

    public function test_starting_the_trial_puts_the_business_on_the_trial_plan_without_a_bill(): void
    {
        $this->postJson('/api/business/subscription/trial')->assertCreated()->assertJsonPath('trial.active', true);

        $subscription = Subscription::where('spa_business_id', $this->business->id)->sole();
        $this->assertTrue($subscription->is_trial);
        $this->assertSame($this->trialPlan->id, $subscription->subscription_plan_id);
        $this->assertSame('Active', $subscription->status);
        $this->assertEqualsWithDelta(14, now()->diffInDays($subscription->expires_at), 0.01);
        $this->assertSame(0, $subscription->billings()->count());

        $this->getJson('/api/business/subscription')
            ->assertJsonPath('trial.active', true)
            ->assertJsonPath('trial.available', false);
    }

    public function test_the_trial_can_only_be_started_once(): void
    {
        $this->postJson('/api/business/subscription/trial')->assertCreated();
        $this->postJson('/api/business/subscription/trial')->assertStatus(422);

        $this->assertSame(1, Subscription::where('spa_business_id', $this->business->id)->count());
    }

    public function test_an_owner_who_used_the_trial_cannot_take_it_again_with_a_new_business(): void
    {
        $this->postJson('/api/business/subscription/trial')->assertCreated();
        $this->assertNotNull($this->owner->fresh()->trial_started_at);

        // The first business is removed and the same owner registers another.
        $this->business->delete();
        SpaBusiness::factory()->create(['owner_id' => $this->owner->id, 'verification_status' => 'Verified']);

        $this->getJson('/api/business/subscription')->assertJsonPath('trial.available', false);
        $this->postJson('/api/business/subscription/trial')->assertStatus(422);
        $this->assertSame(1, Subscription::withTrashed()->where('is_trial', true)->count());
    }

    public function test_an_unverified_business_cannot_start_the_trial(): void
    {
        $this->business->update(['verification_status' => 'Pending']);

        $this->postJson('/api/business/subscription/trial')->assertStatus(422);
        $this->assertSame(0, Subscription::count());
    }

    public function test_a_finished_trial_gets_no_grace_period(): void
    {
        $this->postJson('/api/business/subscription/trial')->assertCreated();
        Subscription::query()->update(['expires_at' => now()->subHour()]);

        $this->getJson('/api/business/subscription')
            ->assertJsonPath('in_grace_period', false)
            ->assertJsonPath('trial.active', false)
            ->assertJsonPath('trial.available', false);
    }

    public function test_the_trial_plan_cannot_be_bought_but_a_paid_plan_can_during_a_trial(): void
    {
        $this->postJson('/api/business/subscription/trial')->assertCreated();

        $this->getJson("/api/business/checkout/quote?purpose=subscribe&plan_uuid={$this->trialPlan->uuid}&billing_cycle=Monthly")
            ->assertOk()
            ->assertJsonPath('data.allowed', false);

        // The running trial doesn't count as "already have a plan".
        $this->getJson("/api/business/checkout/quote?purpose=subscribe&plan_uuid={$this->basic->uuid}&billing_cycle=Monthly")
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.amount', 500);
    }

    public function test_the_public_plan_list_includes_the_trial_plan_with_its_length(): void
    {
        $plans = collect($this->getJson('/api/active-subscription-plans')->assertOk()->json('data'));

        $trial = $plans->firstWhere('category', 'Trial');
        $this->assertNotNull($trial);
        $this->assertTrue($trial['is_trial']);
        $this->assertSame(14, $trial['trial_days']);
        $this->assertFalse($plans->firstWhere('category', 'Basic')['is_trial']);
    }
}
