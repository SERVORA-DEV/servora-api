<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\CheckoutSession;
use App\Models\Payment;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\Subscription\XenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

// Paying on Servora's own checkout (CheckoutService) for a plan change: an
// upgrade pays the difference and applies once Xendit says it is paid — by the
// webhook, or by the status poll when the webhook can't reach the server — and
// only once; a renewal onto a smaller plan has the owner say what to keep, and
// what isn't kept goes on hold. Xendit is mocked: nothing leaves the machine.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=CheckoutUpgradeTest
class CheckoutUpgradeTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    private const WEBHOOK_TOKEN = 'test-callback-token';

    private User $owner;
    private SpaBusiness $business;
    private SubscriptionPlan $basic;
    private SubscriptionPlan $pro;
    private Subscription $subscription;

    /** @var list<array> what was sent to Xendit's createSession */
    private array $sessionsOpened = [];

    /** What Xendit answers when the status poll asks about the session. */
    private array $xenditSession = ['status' => 'ACTIVE'];

    // Payments: never anywhere but the throwaway test database.
    public function refreshDatabase(): void
    {
        if (config('database.connections.'.config('database.default').'.database') !== 'servora_test') {
            throw new \RuntimeException('These tests require the isolated servora_test database.');
        }
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // The secure payment form only loads on the HTTPS site Servora is set up for.
        config(['app.frontend_url' => 'https://app.servora.test']);

        $xendit = Mockery::mock(XenditService::class);
        $xendit->shouldReceive('blockedReason')->andReturn(null);
        $xendit->shouldReceive('isTestMode')->andReturn(true);
        $xendit->shouldReceive('createSession')->andReturnUsing(function (array $payload) {
            $this->sessionsOpened[] = $payload;

            return ['payment_session_id' => 'ps_'.count($this->sessionsOpened), 'components_sdk_key' => 'sdk-key', 'customer_id' => 'cust_1'];
        });
        $xendit->shouldReceive('getSession')->andReturnUsing(fn () => $this->xenditSession);
        $xendit->shouldReceive('verifyWebhookToken')->andReturnUsing(fn ($request) => $request->header('x-callback-token') === self::WEBHOOK_TOKEN);
        $this->app->instance(XenditService::class, $xendit);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->basic = $this->plan('Basic', 'Basic', 500, 1);
        $this->pro = $this->plan('Pro', 'Premium', 1500, 5);

        // Two days into a paid month on Basic.
        $this->subscription = Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $this->basic->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDays(2), 'expires_at' => now()->addDays(28), 'status' => 'Active', 'base_paid' => 500,
        ]);
        Billing::create([
            'subscription_id' => $this->subscription->id, 'spa_business_id' => $this->business->id,
            'billing_type' => 'Subscription', 'billing_number' => 'BIL-'.uniqid(),
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

    private function startCheckout(string $purpose, SubscriptionPlan $plan, array $extra = [])
    {
        return $this->postJson('/api/business/checkout', array_merge([
            'purpose' => $purpose,
            'plan_uuid' => $plan->uuid,
            'billing_cycle' => 'Monthly',
            'method' => 'new',
            'billing_address' => [
                'full_name' => 'Maria Santos', 'email' => 'maria@lotus.test', 'line1' => '12 Acacia St',
                'city' => 'Davao City', 'postal_code' => '8000', 'country' => 'PH',
            ],
        ], $extra));
    }

    private function sessionOf(string $uuid): CheckoutSession
    {
        return CheckoutSession::where('uuid', $uuid)->firstOrFail();
    }

    private function webhook(string $event, CheckoutSession $session, string $token = self::WEBHOOK_TOKEN)
    {
        return $this->postJson('/api/webhooks/xendit', [
            'event' => $event,
            'data' => ['reference_id' => $session->reference_id, 'payment_request_id' => 'pr_123', 'channel_code' => 'GCASH'],
        ], ['x-callback-token' => $token]);
    }

    private function paidBillings(): int
    {
        return Billing::where('subscription_id', $this->subscription->id)->where('status', 'Paid')->count();
    }

    public function test_an_upgrade_paid_through_checkout_applies_once_the_webhook_says_so(): void
    {
        $expires = $this->subscription->expires_at;

        $this->getJson("/api/business/checkout/quote?purpose=upgrade&plan_uuid={$this->pro->uuid}")
            ->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.billing_cycle', 'Monthly')
            ->assertJsonPath('data.current_plan', 'Basic');

        $uuid = $this->startCheckout('upgrade', $this->pro)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1000)
            ->json('data.session_uuid');

        // The payment form asks Xendit for the difference, once.
        $this->assertCount(1, $this->sessionsOpened);
        $this->assertSame(1000.0, (float) $this->sessionsOpened[0]['amount']);
        $this->assertSame('PAY', $this->sessionsOpened[0]['session_type']);
        $this->assertSame($this->sessionOf($uuid)->reference_id, $this->sessionsOpened[0]['reference_id']);

        // Nothing changes before it is paid.
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);
        $this->assertSame(1, $this->paidBillings());

        $this->webhook('payment_session.completed', $this->sessionOf($uuid))->assertOk();

        // The same term, now on Pro; the difference is invoiced and paid.
        $subscription = $this->subscription->fresh();
        $this->assertSame($this->pro->id, $subscription->subscription_plan_id);
        $this->assertSame('Monthly', $subscription->billing_cycle);
        $this->assertTrue($subscription->expires_at->equalTo($expires));
        $this->assertSame(2, $this->paidBillings());
        $this->assertEquals(1500, Billing::where('subscription_id', $subscription->id)->where('status', 'Paid')->sum('amount'));
        $this->assertStringStartsWith('Upgrade: Basic → Pro', Billing::where('subscription_id', $subscription->id)->latest('id')->value('remarks'));

        $payment = Payment::where('reference_number', $this->sessionOf($uuid)->reference_id)->sole();
        $this->assertSame('GCash', $payment->payment_method);
        $this->assertEquals(1000, $payment->amount);
        $this->assertSame('pr_123', $payment->gateway_reference);

        $this->getJson("/api/business/checkout/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.purpose', 'upgrade')
            ->assertJsonPath('data.paid_via', 'GCash')
            ->assertJsonPath('data.plan.name', 'Pro');

        // Xendit sends it again (it retries): nothing is applied or invoiced twice.
        $this->webhook('payment_session.completed', $this->sessionOf($uuid))->assertOk();
        $this->assertSame(2, $this->paidBillings());
        $this->assertSame(1, Payment::where('reference_number', $this->sessionOf($uuid)->reference_id)->count());
    }

    // A local or firewalled server never hears the webhook: the page's status
    // poll asks Xendit and completes the upgrade itself.
    public function test_the_status_poll_applies_an_upgrade_the_webhook_never_reported(): void
    {
        $uuid = $this->startCheckout('upgrade', $this->pro)->assertOk()->json('data.session_uuid');

        // Still being paid: nothing happens yet.
        $this->getJson("/api/business/checkout/{$uuid}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);

        $this->xenditSession = ['status' => 'COMPLETED', 'payment_request_id' => 'pr_123', 'channel_code' => 'GCASH'];
        $this->getJson("/api/business/checkout/{$uuid}")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame($this->pro->id, $this->subscription->fresh()->subscription_plan_id);
        $this->assertSame(2, $this->paidBillings());

        // The webhook arriving late changes nothing more.
        $this->webhook('payment_session.completed', $this->sessionOf($uuid))->assertOk();
        $this->assertSame(2, $this->paidBillings());
    }

    public function test_an_upgrade_left_unpaid_changes_nothing(): void
    {
        $uuid = $this->startCheckout('upgrade', $this->pro)->assertOk()->json('data.session_uuid');

        $this->webhook('payment_session.expired', $this->sessionOf($uuid))->assertOk();

        $this->assertSame('expired', $this->sessionOf($uuid)->status);
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);
        $this->assertSame(1, $this->paidBillings());

        // A completion arriving after it expired doesn't revive it.
        $this->webhook('payment_session.completed', $this->sessionOf($uuid))->assertOk();
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);
        $this->assertSame(1, $this->paidBillings());
    }

    // Only Xendit can say a payment went through.
    public function test_a_webhook_without_xendits_token_applies_nothing(): void
    {
        $uuid = $this->startCheckout('upgrade', $this->pro)->assertOk()->json('data.session_uuid');

        $this->webhook('payment_session.completed', $this->sessionOf($uuid), 'forged-token')->assertForbidden();
        $this->postJson('/api/webhooks/xendit', ['event' => 'payment_session.completed', 'data' => ['reference_id' => $this->sessionOf($uuid)->reference_id]])
            ->assertForbidden();

        $this->assertSame('pending', $this->sessionOf($uuid)->status);
        $this->assertSame($this->basic->id, $this->subscription->fresh()->subscription_plan_id);
        $this->assertSame(1, $this->paidBillings());
    }

    // Checkout re-checks the plan-switch rules; nothing is opened at Xendit when they say no.
    public function test_checkout_refuses_what_is_not_an_upgrade(): void
    {
        $this->subscription->update(['subscription_plan_id' => $this->pro->id]);
        $this->startCheckout('upgrade', $this->basic)
            ->assertStatus(422)
            ->assertJsonPath('message', "That plan isn't an upgrade — switch to it from the Plans page instead.");

        // No paid plan running (it has ended): there is nothing to upgrade.
        $this->subscription->update(['subscription_plan_id' => $this->basic->id, 'status' => 'Expired', 'expires_at' => now()->subDay()]);
        $this->startCheckout('upgrade', $this->pro)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have no active plan to upgrade. Choose a plan to subscribe.');

        $this->assertSame([], $this->sessionsOpened);
        $this->assertSame(0, CheckoutSession::count());
    }

    // Renewing onto a plan smaller than what the business uses: nothing is charged
    // until the owner says which branch to keep; once paid, the other goes on hold.
    public function test_renewing_onto_a_smaller_plan_asks_what_to_keep_and_holds_the_rest(): void
    {
        $this->subscription->update(['subscription_plan_id' => $this->pro->id, 'status' => 'Expired', 'expires_at' => now()->subDay()]);
        $kept = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $other = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        $this->startCheckout('renew', $this->basic)->assertStatus(422);
        $this->assertSame([], $this->sessionsOpened);

        $uuid = $this->startCheckout('renew', $this->basic, ['keep' => ['branch_uuids' => [$kept->uuid]]])
            ->assertOk()
            ->json('data.session_uuid');
        $this->assertSame(500.0, (float) $this->sessionsOpened[0]['amount']);

        $this->webhook('payment_session.completed', $this->sessionOf($uuid))->assertOk();

        $term = Subscription::where('spa_business_id', $this->business->id)->latest('id')->first();
        $this->assertNotSame($this->subscription->id, $term->id);
        $this->assertSame($this->basic->id, $term->subscription_plan_id);
        $this->assertSame('Active', $term->status);
        $this->assertNull($kept->fresh()->plan_hold_at);
        $this->assertNotNull($other->fresh()->plan_hold_at);
    }
}
