<?php

namespace Tests\Feature;

use App\Mail\AccountNoticeMail;
use App\Models\BusinessPaymentMethod;
use App\Models\CheckoutSession;
use App\Models\Notification;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Service\Notifications\PushSender;
use App\Service\Subscription\CheckoutService;
use App\Service\Subscription\PlanFitService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SubscriptionAutoRenewalTest extends TestCase
{
    private const COMMAND = 'subscriptions:auto-renew';

    private const GATEWAY = 'https://xendit.example.test/v3/payment_requests';

    private const REQUEST_ID = 'pr-4cb45102-690a-44d0-9380-6d20354bc36c';

    private User $owner;

    private Subscription $term;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.stores.durable' => ['driver' => 'array'],
            'app.canonical_frontend_url' => 'https://servora.example.test',
            'services.xendit.base_url' => 'https://xendit.example.test',
            'services.xendit.secret_key' => 'xnd_development_test',
            'services.xendit.webhook_token' => 'test-callback-token',
        ]);
        DB::purge('sqlite');
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000100_create_subscription_plans_table.php',
            '2026_01_01_000110_create_system_settings_table.php',
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000180_create_notifications_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000220_create_business_payment_methods_table.php',
            '2026_01_01_000490_create_subscriptions_table.php',
            '2026_01_01_000520_create_checkout_sessions_table.php',
            '2026_01_01_000710_create_billings_table.php',
            '2026_01_01_000750_create_payments_table.php',
            '2026_11_28_020000_add_pending_subscription_renewal_email.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->mock(PushSender::class, fn ($push) => $push->shouldReceive('queue'));
        $this->mock(PlanFitService::class, function ($fit) {
            $fit->shouldReceive('ready')->andReturn(true);
            $fit->shouldReceive('branches', 'accounts')->andReturn(collect());
            $fit->shouldReceive('settle')->andReturn(['branches' => [], 'accounts' => []]);
        });
        Http::preventStrayRequests();

        $this->travelTo(now()->setDate(2026, 12, 28)->setTime(10, 0));
        $this->owner = User::factory()->create(['role' => 'business_owner', 'email' => 'spa-owner@example.test']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $plan = SubscriptionPlan::create([
            'category' => 'Enterprise', 'name' => 'Enterprise', 'monthly_price' => 3499,
            'billing_cycle' => 'Monthly', 'max_branches' => 5, 'max_user_accounts' => 20, 'is_active' => true,
        ]);
        $method = BusinessPaymentMethod::create([
            'spa_business_id' => $business->id, 'type' => 'CARD', 'xendit_payment_token_id' => 'pt-test-renewal',
            'label' => 'Visa ending in 1234', 'status' => 'active', 'is_default' => true,
        ]);
        $this->term = Subscription::create([
            'spa_business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subMonth(), 'expires_at' => now(), 'status' => 'Active', 'base_paid' => 3499,
            'auto_renew' => true, 'payment_method_id' => $method->id,
        ]);
    }

    public function test_due_plan_renews_once_and_emails_the_owner_even_with_zero_grace(): void
    {
        Mail::fake();
        SystemSetting::current()->update(['subscription_grace_period_days' => 0]);
        $this->term->update(['expires_at' => now()->addMinutes(3)]);
        Http::fake([self::GATEWAY => Http::response($this->gatewayResult('SUCCEEDED'))]);

        $this->artisan(self::COMMAND)->assertSuccessful();
        Http::assertNothingSent();
        Mail::assertNothingSent();

        $this->travel(5)->minutes();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $renewed = Subscription::whereKeyNot($this->term->id)->sole();
        $this->assertSame($this->term->subscription_plan_id, $renewed->subscription_plan_id);
        $this->assertTrue($renewed->auto_renew);
        $this->assertTrue($renewed->expires_at->eq(now()->addMonth()));
        $this->assertFalse($this->term->fresh()->auto_renew);
        Http::assertSent(fn ($request) => $request->url() === self::GATEWAY
            && (float) $request['request_amount'] === 3499.0
            && $request['payment_token_id'] === 'pt-test-renewal'
            && $request['channel_properties']['card_on_file_type'] === 'RECURRING');

        $notice = Notification::where('user_id', $this->owner->id)->sole();
        $this->assertSame('Subscription Renewed', $notice->title);
        $this->assertStringContainsString('Enterprise plan renewed automatically', $notice->message);
        $this->assertStringContainsString('₱3,499.00', $notice->message);
        $this->assertStringContainsString('January 28, 2027', $notice->message);
        Mail::assertSent(AccountNoticeMail::class, fn ($mail) => $mail->hasTo($this->owner->email)
            && $mail->heading === $notice->title && $mail->body === $notice->message
            && $mail->actionUrl === 'https://servora.example.test/business/subscription');

        $session = CheckoutSession::sole();
        app(CheckoutService::class)->complete($session, $this->gatewayResult('SUCCEEDED'));
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('billings', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($session->fresh()->pending_renewal_email);
        Http::assertSentCount(1);
        Mail::assertSentCount(1);
    }

    public function test_a_pending_charge_waits_for_confirmation_before_renewing_or_emailing(): void
    {
        Mail::fake();
        Http::fake([
            self::GATEWAY => Http::response($this->gatewayResult('ACCEPTING_PAYMENTS')),
            self::GATEWAY.'/'.self::REQUEST_ID => Http::response($this->gatewayResult('SUCCEEDED')),
        ]);
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billings', 0);
        Mail::assertNothingSent();

        $this->travel(5)->minutes();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('billings', 1);
        Http::assertSentCount(2);
        Mail::assertSentCount(1);
    }

    public function test_a_declined_charge_or_disabled_renewal_sends_no_success_email(): void
    {
        Mail::fake();
        Http::fake([self::GATEWAY => Http::response($this->gatewayResult('FAILED') + ['failure_code' => 'CARD_DECLINED'])]);
        $this->term->update(['auto_renew' => false]);
        $this->artisan(self::COMMAND)->assertSuccessful();
        Http::assertNothingSent();

        $this->term->update(['auto_renew' => true]);
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billings', 0);
        $this->assertSame('Auto-Renewal Failed', Notification::sole()->title);
        Mail::assertNothingSent();
    }

    public function test_mail_failure_does_not_undo_the_renewal_and_the_next_run_retries_only_email(): void
    {
        Http::fake([self::GATEWAY => Http::response($this->gatewayResult('SUCCEEDED'))]);
        $outbox = Mockery::mock();
        $outbox->shouldReceive('send')->once()->andThrow(new TransportException('Test mail service outage'));
        $mailManager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with($this->owner->email)->andReturn($outbox);

        try {
            $this->artisan(self::COMMAND)->assertSuccessful();
        } finally {
            Mail::swap($mailManager);
        }
        $this->assertDatabaseCount('subscriptions', 2);
        $session = CheckoutSession::sole();
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->pending_renewal_email);

        Mail::fake();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->artisan(self::COMMAND)->assertSuccessful();
        $this->assertNull($session->fresh()->pending_renewal_email);
        $this->assertDatabaseCount('notifications', 1);
        Http::assertSentCount(1);
        Mail::assertSentCount(1);
    }

    public function test_a_verified_callback_can_settle_a_charge_whose_response_was_lost(): void
    {
        Mail::fake();
        Http::fake([
            self::GATEWAY => fn () => throw new ConnectionException('Test connection timeout'),
            self::GATEWAY.'/'.self::REQUEST_ID => fn () => Http::response($this->gatewayResult('SUCCEEDED') + [
                'reference_id' => CheckoutSession::sole()->reference_id,
            ]),
        ]);
        $this->artisan(self::COMMAND)->assertSuccessful();
        $session = CheckoutSession::sole();
        $this->assertSame('pending', $session->status);
        Mail::assertNothingSent();

        $this->postJson('/api/webhooks/xendit', [
            'event' => 'payment.capture',
            'data' => ['reference_id' => $session->reference_id, 'payment_request_id' => self::REQUEST_ID],
        ], ['x-callback-token' => 'test-callback-token'])->assertOk();

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('billings', 1);
        Mail::assertSentCount(1);
    }

    private function gatewayResult(string $status): array
    {
        return ['status' => $status, 'payment_request_id' => self::REQUEST_ID, 'channel_code' => 'CARDS'];
    }
}
