<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Service\PlanAccessService;
use App\Service\PlanSwitchService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Quotes exercise real service rules in a disposable SQLite fixture, without the historical migration chain. */
class PlanSwitchQuoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'subscription_quotes', 'database.connections.subscription_quotes' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('subscription_quotes');
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('subscription_grace_period_days');
            $table->boolean('almost_due_notify_enabled');
            $table->integer('almost_due_notify_days_before');
            $table->boolean('almost_due_repeat_enabled');
            $table->integer('almost_due_repeat_every_days');
            $table->boolean('plan_changes_enabled');
            $table->integer('plan_change_grace_days');
            $table->timestamps();
        });
    }

    private function plan(int $id, float $price, int $accounts = 3): SubscriptionPlan
    {
        return (new SubscriptionPlan)->forceFill([
            'id' => $id, 'category' => 'Basic', 'name' => 'Plan '.$id,
            'monthly_price' => $price, 'yearly_price' => $price * 10,
            'max_branches' => 1, 'max_user_accounts' => $accounts,
            'extra_account_price' => 49, 'is_active' => true,
        ]);
    }

    private function term(SubscriptionPlan $plan): Subscription
    {
        $sub = (new Subscription)->forceFill([
            'subscription_plan_id' => $plan->id, 'status' => 'Active', 'billing_cycle' => 'Monthly',
            'starts_at' => now(), 'expires_at' => now()->addMonth(),
            'base_paid' => $plan->monthly_price, 'extra_accounts' => 2, 'extras_paid' => 98,
        ]);
        $sub->setRelation('plan', $plan);
        $sub->setRelation('business', null);

        return $sub;
    }

    public function test_upgrade_keeps_extra_payments_separate_from_base_credit(): void
    {
        $term = $this->term($this->plan(1, 499));
        $quote = app(PlanSwitchService::class)->quote($term, $this->plan(2, 999, 10), 'Monthly');
        $this->assertTrue($quote['allowed']);
        $this->assertSame('upgrade', $quote['type']);
        $this->assertSame(500.0, $quote['amount_due']);
    }

    public function test_more_accounts_charge_only_the_unpaid_difference(): void
    {
        $plan = $this->plan(1, 499);
        $quote = app(PlanSwitchService::class)->quote($this->term($plan), $plan, 'Monthly', ['extra_accounts' => 3]);
        $this->assertTrue($quote['allowed']);
        $this->assertSame('upgrade', $quote['type']);
        $this->assertSame(49.0, $quote['amount_due']);
    }

    public function test_reducing_capacity_waits_without_a_refund(): void
    {
        $plan = $this->plan(1, 499);
        $quote = app(PlanSwitchService::class)->quote($this->term($plan), $plan, 'Monthly', ['extra_accounts' => 1]);
        $this->assertTrue($quote['allowed']);
        $this->assertSame('downgrade', $quote['type']);
        $this->assertSame(0.0, $quote['amount_due']);
    }

    public function test_yearly_switch_collects_twelve_months_of_extras_at_renewal(): void
    {
        $plan = $this->plan(1, 499);
        $quote = app(PlanSwitchService::class)->quote($this->term($plan), $plan, 'Yearly', ['extra_accounts' => 2]);
        $this->assertSame('downgrade', $quote['type']);
        $this->assertSame(6166.0, $quote['new_price']);
        $this->assertSame(0.0, $quote['amount_due']);
    }

    public function test_the_additive_migration_preserves_old_rows_and_defaults_to_no_extras(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
        });
        DB::table('subscription_plans')->insert(['name' => 'Purchased terms']);
        DB::table('subscriptions')->insert(['id' => 1]);
        $migration = require database_path('migrations/2026_11_09_100000_add_subscription_entitlements.php');
        $migration->up();
        $this->assertSame('Purchased terms', DB::table('subscription_plans')->value('name'));
        $this->assertNull(DB::table('subscription_plans')->value('app_booking_limit'));
        $this->assertNull(DB::table('subscription_plans')->value('export_mode'));
        $this->assertEquals(0, DB::table('subscriptions')->value('extra_accounts'));
        $migration->down();
        $this->assertSame('Purchased terms', DB::table('subscription_plans')->value('name'));
    }

    private function quotaFixture(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->boolean('mobile_app_access');
            $table->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->integer('spa_business_id');
            $table->integer('subscription_plan_id');
            $table->string('status');
            $table->boolean('is_trial')->default(false);
            $table->string('plan_change_status')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('spa_branches', function (Blueprint $table) {
            $table->id();
            $table->integer('spa_business_id');
            $table->softDeletes();
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->integer('spa_branch_id');
            $table->string('source');
            $table->string('status')->default('cancelled');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('subscription_plans')->insert(['id' => 1, 'mobile_app_access' => true]);
        DB::table('subscriptions')->insert(['id' => 1, 'spa_business_id' => 1, 'subscription_plan_id' => 1, 'status' => 'Active', 'expires_at' => now()->addMonth(), 'created_at' => now()]);
        DB::table('spa_branches')->insert([['id' => 1, 'spa_business_id' => 1], ['id' => 2, 'spa_business_id' => 1], ['id' => 3, 'spa_business_id' => 2]]);
    }

    // App bookings have no monthly limit: a plan with the client app listing takes as many as come.
    public function test_app_bookings_have_no_monthly_limit(): void
    {
        $this->quotaFixture();
        for ($i = 0; $i < 40; $i++) {
            DB::table('appointments')->insert(['spa_branch_id' => 1, 'source' => 'Mobile', 'created_at' => now()]);
        }

        app(PlanAccessService::class)->assertCanBook(1);
        $this->addToAssertionCount(1);
    }

    public function test_a_plan_without_the_client_app_listing_still_takes_no_app_bookings(): void
    {
        $this->quotaFixture();
        DB::table('subscription_plans')->update(['mobile_app_access' => false]);

        $this->expectException(HttpException::class);
        app(PlanAccessService::class)->assertCanBook(1);
    }
}
