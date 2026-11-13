<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'admin_access.allowed_ips' => [], 'admin_access.require_two_factor' => false]);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->string('role'); $t->string('audience');
            $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id');
            foreach (AdminPermissions::keys() as $key) $t->boolean($key)->default(false);
            $t->timestamps();
        });
        Schema::create('spa_businesses', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->string('business_name'); $t->softDeletes();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id(); $t->string('category'); $t->string('name'); $t->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->foreignId('spa_business_id'); $t->foreignId('subscription_plan_id');
            $t->string('billing_cycle')->nullable(); $t->boolean('is_trial')->default(false); $t->string('status')->default('Active');
            $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->softDeletes();
        });
        Schema::create('billings', function (Blueprint $t) {
            $t->id(); $t->foreignId('subscription_id')->nullable(); $t->foreignId('spa_business_id')->nullable();
            $t->string('billing_type')->default('Subscription'); $t->decimal('amount', 10, 2)->default(9999); $t->softDeletes();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->string('uuid'); $t->foreignId('billing_id'); $t->foreignId('spa_business_id')->nullable();
            $t->string('reference_number')->nullable(); $t->string('payment_status')->default('Paid');
            $t->decimal('amount', 10, 2); $t->decimal('refunded_amount', 10, 2)->nullable(); $t->timestamp('paid_at')->nullable(); $t->softDeletes();
        });
        Schema::create('checkout_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('subscription_id')->nullable(); $t->foreignId('subscription_plan_id')->nullable();
            $t->string('reference_id')->unique(); $t->string('status')->default('completed'); $t->string('purpose')->default('subscribe');
            $t->string('billing_cycle')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->string('xendit_payment_request_id')->nullable();
        });
        DB::table('spa_businesses')->insert(['id' => 1, 'uuid' => 'business-1', 'business_name' => 'Test Spa']);
        DB::table('subscription_plans')->insert([
            ['id' => 1, 'category' => 'Basic', 'name' => 'Original Basic', 'deleted_at' => '2026-10-01 00:00:00'],
            ['id' => 2, 'category' => 'Premium', 'name' => 'Premium', 'deleted_at' => null],
            ['id' => 3, 'category' => 'Trial', 'name' => 'Free Trial', 'deleted_at' => null],
        ]);
    }

    private function signIn(string $role = 'system_administrator', bool $permission = true): void
    {
        $user = User::create(['role' => $role, 'first_name' => 'Test', 'last_name' => 'Admin']);
        UserPermission::create(['user_id' => $user->id, 'report_view' => $permission]);
        Sanctum::actingAs($user);
    }

    private function term(int $id, array $extra = []): void
    {
        DB::table('subscriptions')->insert(array_merge(['id' => $id, 'uuid' => "term-$id", 'spa_business_id' => 1,
            'subscription_plan_id' => 2, 'billing_cycle' => 'Monthly', 'starts_at' => '2026-10-01 00:00:00', 'expires_at' => '2026-11-01 00:00:00'], $extra));
    }

    private function payment(int $id, array $extra = [], array $billing = []): void
    {
        DB::table('billings')->insert(array_merge(['id' => $id, 'subscription_id' => 1, 'spa_business_id' => 1], $billing));
        DB::table('payments')->insert(array_merge(['id' => $id, 'uuid' => "payment-$id", 'billing_id' => $id, 'amount' => 100.25,
            'paid_at' => '2026-10-01 00:00:00', 'reference_number' => "reference-$id"], $extra));
    }

    public function test_collections_use_actual_confirmed_payments_and_preserve_refunds(): void
    {
        $this->signIn(); $this->term(1); $this->term(2, ['is_trial' => true, 'subscription_plan_id' => 3]);
        $this->payment(1, ['refunded_amount' => 20.10]);
        $this->payment(2, ['payment_status' => 'Refunded', 'refunded_amount' => 100.25]);
        $this->payment(3, ['payment_status' => 'Pending']);
        $this->payment(4, ['payment_status' => 'Failed']);
        $this->payment(5, ['payment_status' => 'Voided']);
        $this->payment(6, ['paid_at' => null]);
        $this->payment(7, [], ['billing_type' => 'Appointment']);
        $this->payment(8, [], ['subscription_id' => 2]);
        $this->payment(9, ['deleted_at' => now()]);
        $this->payment(10, [], ['deleted_at' => now()]);
        $rows = $this->getJson('/api/system/reports/subscriptions?section=payments')->assertOk()->assertJsonPath('total', 2)->json('data');
        $this->assertSame(['payment-1', 'payment-2'], array_column($rows, 'uuid'));
        $this->assertEquals(200.50, array_sum(array_column($rows, 'amount')));
        $this->assertEquals(120.35, array_sum(array_column($rows, 'refunded_amount')));
        $this->assertSame('business-1', $rows[0]['business_uuid']);
        $this->assertSame('Premium', $rows[0]['plan']);
        $this->assertArrayNotHasKey('reference_number', $rows[0]);
        $this->assertSame(10, DB::table('payments')->count());
        $this->assertSame(9999, (int) DB::table('billings')->value('amount'));
    }

    public function test_checkout_history_preserves_the_purchased_plan_and_cycle_after_an_upgrade(): void
    {
        $this->signIn(); $this->term(1); $this->term(2);
        $this->payment(1); $this->payment(2);
        DB::table('checkout_sessions')->insert([
            ['subscription_id' => 1, 'subscription_plan_id' => 1, 'reference_id' => 'reference-1', 'billing_cycle' => 'Yearly', 'purpose' => 'subscribe', 'completed_at' => '2026-10-01 00:00:00', 'xendit_payment_request_id' => 'private'],
            ['subscription_id' => 1, 'subscription_plan_id' => 2, 'reference_id' => 'upgrade-1', 'billing_cycle' => 'Yearly', 'purpose' => 'upgrade', 'completed_at' => '2026-10-10 00:00:00', 'xendit_payment_request_id' => 'private'],
            // A coinciding reference on another subscription must not misattribute a payment.
            ['subscription_id' => 2, 'subscription_plan_id' => 1, 'reference_id' => 'reference-2', 'billing_cycle' => 'Yearly', 'purpose' => 'subscribe', 'completed_at' => '2026-10-01 00:00:00', 'xendit_payment_request_id' => 'private'],
        ]);
        $response = $this->getJson('/api/system/reports/subscriptions?section=payments')->assertOk()->assertJsonPath('total', 2);
        $response->assertJsonPath('data.0.plan', 'Basic')->assertJsonPath('data.0.plan_name', 'Original Basic')->assertJsonPath('data.0.billing_cycle', 'Yearly');
        $response->assertJsonPath('data.1.plan', 'Premium')->assertJsonPath('data.1.billing_cycle', 'Monthly');
        $terms = $this->getJson('/api/system/reports/subscriptions?section=subscriptions')->assertOk();
        $terms->assertJsonPath('data.0.plan_changes.0.plan', 'Basic')->assertJsonPath('data.0.plan_changes.1.plan', 'Premium')
            ->assertJsonPath('data.0.plan_changes.1.purpose', 'upgrade');
        $this->assertStringNotContainsString('private', $terms->getContent());
    }

    public function test_terms_include_expired_cancelled_and_deleted_history_but_exclude_unstarted_attempts(): void
    {
        $this->signIn(); $this->term(1, ['status' => 'Expired']);
        $this->term(2, ['status' => 'Cancelled', 'cancelled_at' => '2026-10-05 00:00:00']);
        $this->term(3, ['deleted_at' => '2026-10-06 00:00:00', 'subscription_plan_id' => 3, 'is_trial' => true]);
        $this->term(4, ['status' => 'Pending']); $this->term(5, ['starts_at' => null]);
        DB::table('spa_businesses')->where('id', 1)->update(['deleted_at' => '2026-10-20 00:00:00']);
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson("/api/system/reports/subscriptions?section=subscriptions&per_page=1&page=$page")
                ->assertOk()->assertJsonPath('last_page', 3)->assertJsonCount(1, 'data');
            $ids[] = $response->json('data.0.uuid');
            $this->assertNotNull($response->json('data.0.business_deleted_at'));
            $this->assertArrayNotHasKey('id', $response->json('data.0'));
        }
        $this->assertSame(['term-1', 'term-2', 'term-3'], $ids);
        $this->getJson('/api/system/reports/subscriptions?section=subscriptions&page=3&per_page=1')->assertJsonPath('data.0.is_trial', true);
    }

    public function test_empty_sections_are_read_only_and_validate_pagination(): void
    {
        $this->signIn();
        foreach (['subscriptions', 'payments'] as $section) {
            $this->getJson("/api/system/reports/subscriptions?section=$section")->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson('/api/system/reports/subscriptions')->assertUnprocessable();
        $this->getJson('/api/system/reports/subscriptions?section=secrets')->assertUnprocessable();
        $this->getJson('/api/system/reports/subscriptions?section=payments&per_page=999')->assertUnprocessable();
        $this->getJson('/api/system/reports/subscriptions?section=payments&page=0')->assertUnprocessable();
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertSame(0, DB::table('checkout_sessions')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_both_sections_require_a_system_administrator_with_report_permission(): void
    {
        $this->getJson('/api/system/reports/subscriptions?section=payments')->assertUnauthorized();
        $this->signIn(permission: false);
        foreach (['subscriptions', 'payments'] as $section) $this->getJson("/api/system/reports/subscriptions?section=$section")->assertForbidden();
        $this->signIn('business_owner');
        $this->getJson('/api/system/reports/subscriptions?section=subscriptions')->assertForbidden();
    }
}
