<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Service\SubscriptionService;
use App\Service\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

// An owner switching their live plan under the admin's Subscription Policy
// (PlanSwitchService): changes are open for a grace period after subscribing,
// then locked until the due date. Inside it, an upgrade charges new price −
// paid this term and applies at once; a downgrade waits for the next billing.
// Xendit is mocked.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=SubscriptionPlanSwitchTest
class SubscriptionPlanSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SubscriptionPlan $basic;
    private SubscriptionPlan $pro;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $xendit = Mockery::mock(XenditService::class);
        $xendit->shouldReceive('createInvoice')->andReturnUsing(fn ($id, $amount) => [
            'invoice_url' => "https://example.invalid/{$id}", 'status' => 'PENDING', 'amount' => $amount,
        ]);
        $xendit->shouldReceive('getInvoiceByExternalId')->andReturn(null);
        $this->app->instance(XenditService::class, $xendit);

        SystemSetting::current()->update(['plan_changes_enabled' => true, 'plan_change_grace_days' => 7]);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->basic = $this->plan('Basic', 'Basic', 500, 1);
        $this->pro = $this->plan('Pro', 'Premium', 1500, 5);

        $this->subscription = Subscription::create([
            'spa_business_id' => $this->business->id,
            'subscription_plan_id' => $this->basic->id,
            'billing_cycle' => 'Monthly',
            // Two days into a 30-day term: inside the 7-day grace period.
            'starts_at' => now()->subDays(2),
            'expires_at' => now()->addDays(28),
            'status' => 'Active',
        ]);
        Billing::create([
            'subscription_id' => $this->subscription->id, 'spa_business_id' => $this->business->id,
            'billing_type' => 'Subscription', 'billing_number' => 'BIL-' . uniqid(),
            'amount' => 500, 'status' => 'Paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);

        Sanctum::actingAs($this->owner);
    }

    private function plan(string $name, string $category, float $monthly, int $branches): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'category' => $category, 'name' => $name, 'monthly_price' => $monthly, 'yearly_price' => $monthly * 10,
            'billing_cycle' => 'Both', 'max_branches' => $branches, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
    }

    private function change(SubscriptionPlan $plan, string $cycle = 'Monthly', string $method = 'postJson')
    {
        return $this->{$method}('/api/business/subscription/change', ['subscription_plan_uuid' => $plan->uuid, 'billing_cycle' => $cycle]);
    }

    public function test_an_upgrade_costs_the_difference_and_applies_once_paid(): void
    {
        $this->getJson("/api/business/subscription/change/quote?subscription_plan_uuid={$this->pro->uuid}&billing_cycle=Monthly")
            ->assertOk()
            ->assertJsonPath('data.type', 'upgrade')
            ->assertJsonPath('data.amount_due', 1000);

        $reference = $this->change($this->pro)->assertOk()->assertJsonPath('amount_due', 1000)->json('reference_id');
        $expires = $this->subscription->expires_at;

        $this->assertTrue(app(SubscriptionService::class)->activateSubscriptionFromPayment($reference, 'inv_1', 'EWALLET', 'PH_GCASH', 1000));

        $this->subscription->refresh();
        $this->assertSame($this->pro->id, $this->subscription->subscription_plan_id);
        $this->assertTrue($this->subscription->expires_at->equalTo($expires));
        $this->assertEquals(1500, $this->subscription->billings()->where('status', 'Paid')->sum('amount'));

        // The webhook and the confirm fallback can't both apply it.
        $this->assertFalse(app(SubscriptionService::class)->activateSubscriptionFromPayment($reference, 'inv_1', 'EWALLET', 'PH_GCASH', 1000));
    }

    public function test_a_downgrade_waits_for_the_next_billing_and_can_be_undone(): void
    {
        $this->subscription->update(['subscription_plan_id' => $this->pro->id]);

        $this->change($this->basic)->assertOk()->assertJsonPath('type', 'downgrade');
        $this->subscription->refresh();
        $this->assertSame($this->pro->id, $this->subscription->subscription_plan_id);
        $this->assertSame($this->basic->id, $this->subscription->scheduled_plan_id);
        $this->getJson('/api/business/subscription')->assertJsonPath('scheduled_change.plan.name', 'Basic');

        $this->deleteJson('/api/business/subscription/change')->assertOk();
        $this->assertNull($this->subscription->fresh()->scheduled_plan_id);
    }

    public function test_changes_are_locked_once_the_grace_period_has_passed(): void
    {
        $this->subscription->update(['starts_at' => now()->subDays(8), 'expires_at' => now()->addDays(22)]);

        // Upgrade refused...
        $this->change($this->pro)->assertStatus(422);
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);

        // ...and so is a downgrade.
        $this->subscription->update(['subscription_plan_id' => $this->pro->id]);
        $this->change($this->basic)->assertStatus(422);
        $this->assertNull($this->subscription->fresh()->scheduled_plan_id);

        $this->getJson("/api/business/subscription/change/quote?subscription_plan_uuid={$this->basic->uuid}&billing_cycle=Monthly")
            ->assertOk()
            ->assertJsonPath('data.allowed', false);
    }

    public function test_the_grace_period_follows_the_admin_setting(): void
    {
        $this->subscription->update(['starts_at' => now()->subDays(8), 'expires_at' => now()->addDays(22)]);
        SystemSetting::current()->update(['plan_change_grace_days' => 14]);

        $this->change($this->pro)->assertOk();
    }

    public function test_a_scheduled_downgrade_can_still_be_taken_back_after_the_grace_period(): void
    {
        $this->subscription->update(['subscription_plan_id' => $this->pro->id]);
        $this->change($this->basic)->assertOk();

        // The grace period passes with the downgrade still scheduled.
        $this->subscription->update(['starts_at' => now()->subDays(10), 'expires_at' => now()->addDays(20)]);

        $this->deleteJson('/api/business/subscription/change')->assertOk();
        $this->assertNull($this->subscription->fresh()->scheduled_plan_id);
    }

    public function test_the_owner_is_told_when_the_grace_period_ends(): void
    {
        $policy = $this->getJson('/api/business/subscription')->assertOk()->json('plan_policy');

        $this->assertSame(7, $policy['grace_days']);
        $this->assertTrue($this->subscription->starts_at->copy()->addDays(7)->equalTo($policy['change_until']));
    }

    public function test_plan_changes_can_be_switched_off(): void
    {
        SystemSetting::current()->update(['plan_changes_enabled' => false]);

        $this->change($this->pro)->assertStatus(422);
    }

    public function test_an_over_limit_downgrade_is_warned_and_its_renewal_refused(): void
    {
        $this->subscription->update(['subscription_plan_id' => $this->pro->id]);
        SpaBranch::factory()->count(2)->create(['spa_business_id' => $this->business->id]);

        $this->change($this->basic)->assertOk()->assertJsonCount(1, 'over_limits');

        $this->subscription->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/business/subscription', ['subscription_plan_uuid' => $this->basic->uuid, 'billing_cycle' => 'Monthly'])
            ->assertStatus(422)
            ->assertJsonCount(1, 'over_limits');
    }

    public function test_owners_cannot_edit_their_subscription_row(): void
    {
        $this->putJson("/api/business/subscription/{$this->subscription->uuid}", ['subscription_plan_id' => $this->pro->id])
            ->assertNotFound();
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);
    }
}
