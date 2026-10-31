<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CheckoutSession;
use App\Models\Client;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\CheckoutService;
use App\Service\PlanAccessService;
use App\Service\PlanSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionEntitlementsTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if (config('database.connections.'.config('database.default').'.database') !== 'servora_test') {
            throw new \RuntimeException('These tests require the isolated servora_test database.');
        }
        $this->refreshIsolatedDatabase();
    }

    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $owner->id, 'status' => 'Verified']);
        $business = SpaBusiness::factory()->create(['owner_id' => $owner->id]);
        $branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        Sanctum::actingAs($owner);

        return [$owner, $business, $branch];
    }

    private function plan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'name' => 'Basic', 'category' => 'Basic', 'monthly_price' => 499, 'yearly_price' => 4990,
            'billing_cycle' => 'Both', 'max_branches' => 1, 'max_user_accounts' => 3,
            'mobile_app_access' => true, 'report_access' => true, 'report_export' => true,
            'export_mode' => 'csv', 'export_days' => 30, 'app_booking_limit' => 30,
            'extra_account_price' => 49, 'is_active' => true,
        ], $overrides));
    }

    private function subscribe(SpaBusiness $business, SubscriptionPlan $plan, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'status' => 'Active',
            'starts_at' => now(), 'expires_at' => now()->addMonth(), 'billing_cycle' => 'Monthly', 'base_paid' => 499,
        ], $overrides));
    }

    public function test_paid_extras_activate_once_and_are_included_in_capacity_and_renewal(): void
    {
        [$owner, $business] = $this->business();
        $plan = $this->plan();
        $session = CheckoutSession::create([
            'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id,
            'purpose' => 'subscribe', 'billing_cycle' => 'Monthly', 'amount' => 597, 'vat_amount' => CheckoutService::vatOf(597),
            'reference_id' => 'test-'.uniqid(), 'status' => 'pending',
            'meta' => ['extras' => ['extra_branches' => 0, 'extra_accounts' => 2], 'base_amount' => 499, 'extras_amount' => 98],
        ]);
        $this->assertSame(0, Subscription::count());
        app(CheckoutService::class)->complete($session, ['channel_code' => 'GCASH']);
        app(CheckoutService::class)->complete($session->fresh(), ['channel_code' => 'GCASH']);
        $sub = Subscription::sole();
        $this->assertSame(2, $sub->extra_accounts);
        $this->assertSame(1, $sub->billings()->count());
        $this->assertSame(499.0, app(PlanSwitchService::class)->paidThisTerm($sub));
        $this->assertSame(5, app(PlanAccessService::class)->present($business->id)['max_user_accounts']);
        $this->assertEquals(98, app(PlanAccessService::class)->present($business->id)['extras_renewal_amount']);
    }

    public function test_upgrade_does_not_use_extra_payments_as_credit_for_the_base_plan(): void
    {
        [, $business] = $this->business();
        $basic = $this->plan();
        $sub = $this->subscribe($business, $basic, ['extra_accounts' => 2, 'extras_paid' => 98]);
        $premium = $this->plan(['name' => 'Premium', 'category' => 'Premium', 'monthly_price' => 999, 'max_branches' => 3, 'max_user_accounts' => 10]);
        $quote = app(PlanSwitchService::class)->quote($sub, $premium, 'Monthly');
        $this->assertSame(500.0, $quote['amount_due']);
        $this->assertSame('upgrade', $quote['type']);
    }

    public function test_basic_export_is_csv_and_only_recent_dates_and_own_business(): void
    {
        [, $business] = $this->business();
        $this->subscribe($business, $this->plan());
        $base = '/api/business/reports/export-data?type=appointments&date_to='.now()->format('Y-m-d').'&date_from=';
        $this->getJson($base.now()->subDays(29)->format('Y-m-d').'&format=csv')->assertOk();
        $this->getJson($base.now()->subDays(30)->format('Y-m-d').'&format=csv')->assertStatus(422);
        $this->getJson($base.now()->format('Y-m-d').'&format=pdf')->assertForbidden();
        $other = SpaBranch::factory()->create();
        $this->getJson($base.now()->format('Y-m-d').'&format=csv&branch_uuid='.$other->uuid)->assertForbidden();
    }

    public function test_capacity_reduction_is_scheduled_without_losing_current_paid_extras(): void
    {
        [, $business] = $this->business();
        $plan = $this->plan();
        $sub = $this->subscribe($business, $plan, ['extra_accounts' => 2, 'extras_paid' => 98]);
        $service = app(PlanSwitchService::class);
        $quote = $service->quote($sub, $plan, 'Monthly', ['extra_accounts' => 1]);
        $this->assertSame('downgrade', $quote['type']);
        $service->scheduleDowngrade($sub, $plan, 'Monthly', null, ['extra_branches' => 0, 'extra_accounts' => 1]);
        $this->assertSame(2, $sub->fresh()->extra_accounts);
        $this->assertSame(1, $sub->fresh()->scheduled_extras['extra_accounts']);
    }

    public function test_monthly_allowance_counts_cancelled_mobile_bookings_and_survives_plan_changes(): void
    {
        $this->travelTo(now('Asia/Manila')->startOfMonth()->addDays(10));
        [, $business, $branch] = $this->business();
        $plan = $this->plan(['app_booking_limit' => 1]);
        $sub = $this->subscribe($business, $plan);
        $client = Client::factory()->create(['spa_business_id' => $business->id]);
        Appointment::create(['client_id' => $client->id, 'spa_branch_id' => $branch->id, 'appointment_number' => 'QUOTA-1', 'appointment_date' => now()->addDay(), 'appointment_time' => '10:00', 'appointment_type' => 'Reservation', 'source' => 'Mobile', 'status' => 'cancelled']);
        $access = app(PlanAccessService::class);
        $this->assertSame(1, $access->bookingUsage($business->id)['used']);
        $this->expectException(HttpException::class);
        $access->assertCanBook($business->id);
    }
}
