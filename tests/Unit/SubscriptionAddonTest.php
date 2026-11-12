<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Service\PlanSwitchService;
use App\Service\SubscriptionAddonService;
use App\Support\PlanTerms;
use App\Support\SlotProration;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Extra slots bought mid-term: prorated price, what the next billing
 * becomes, and applying a paid purchase. No shared database: quotes run on
 * unsaved models, applying runs on a disposable in-memory SQLite table.
 */
class SubscriptionAddonTest extends TestCase
{
    private function enterprise(array $overrides = []): SubscriptionPlan
    {
        return (new SubscriptionPlan)->forceFill([
            'id' => 3, 'category' => 'Enterprise', 'name' => 'Enterprise',
            'monthly_price' => 3000, 'yearly_price' => 30000,
            'max_branches' => 5, 'max_user_accounts' => 20,
            'extra_account_price' => 100, 'extra_branch_price' => 500, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function term(SubscriptionPlan $plan, string $start = '2026-01-01', string $due = '2026-02-01', array $overrides = []): Subscription
    {
        $sub = (new Subscription)->forceFill([
            'id' => 1, 'spa_business_id' => 1, 'subscription_plan_id' => $plan->id, 'status' => 'Active',
            'billing_cycle' => 'Monthly', 'auto_renew' => true, 'is_trial' => false,
            'starts_at' => Carbon::parse($start, 'Asia/Manila'), 'expires_at' => Carbon::parse($due, 'Asia/Manila'),
            'extra_branches' => 0, 'extra_accounts' => 0, 'extras_paid' => 0,
            ...$overrides,
        ]);
        $sub->setRelation('plan', $plan);
        $sub->setRelation('scheduledPlan', null);
        $sub->setRelation('pendingPlan', null);

        return $sub;
    }

    // ── Proration math ───────────────────────────────────────────────────

    public function test_the_days_left_count_the_day_it_is_bought(): void
    {
        $days = SlotProration::days(Carbon::parse('2026-01-01', 'Asia/Manila'), Carbon::parse('2026-02-01', 'Asia/Manila'), Carbon::parse('2026-01-15 14:30', 'Asia/Manila'));
        $this->assertSame([17, 31], $days);
    }

    public function test_one_account_halfway_costs_about_half_the_monthly_price(): void
    {
        // ₱100 × 17 ÷ 31 = ₱54.84 → ₱55
        $this->assertSame(55, SlotProration::amount(100, 1, 17, 31));
    }

    public function test_two_accounts_with_17_of_31_days_left_cost_110(): void
    {
        // 2 × ₱100 × 17 ÷ 31 = ₱109.68 → ₱110
        $this->assertSame(110, SlotProration::amount(100, 2, 17, 31));
    }

    public function test_buying_on_the_first_day_charges_the_full_price_and_on_the_last_day_one_day(): void
    {
        $start = Carbon::parse('2026-01-01', 'Asia/Manila');
        $due = Carbon::parse('2026-02-01', 'Asia/Manila');
        $this->assertSame([31, 31], SlotProration::days($start, $due, $start->copy()->addHours(9)));
        $this->assertSame([1, 31], SlotProration::days($start, $due, Carbon::parse('2026-01-31 23:00', 'Asia/Manila')));
        $this->assertSame(100, SlotProration::amount(100, 1, 31, 31));
        $this->assertSame(3, SlotProration::amount(100, 1, 1, 31));
    }

    public function test_a_utc_time_is_counted_in_manila_days(): void
    {
        // 2026-01-14 17:00 UTC is already Jan 15 01:00 in Manila.
        $days = SlotProration::days(Carbon::parse('2026-01-01', 'Asia/Manila'), Carbon::parse('2026-02-01', 'Asia/Manila'), Carbon::parse('2026-01-14 17:00', 'UTC'));
        $this->assertSame([17, 31], $days);
    }

    public function test_nothing_bought_costs_nothing_and_a_tiny_charge_is_at_least_one_peso(): void
    {
        $this->assertSame(0, SlotProration::amount(100, 0, 17, 31));
        $this->assertSame(1, SlotProration::amount(10, 1, 1, 365));
    }

    public function test_yearly_billing_prorates_twelve_months_of_the_slot_price(): void
    {
        $plan = $this->enterprise();
        $this->assertSame(1200.0, PlanTerms::slotPrice($plan, 'account', 'Yearly'));
        // ₱1,200 × 100 ÷ 365 = ₱328.77 → ₱329
        $this->assertSame(329, SlotProration::amount(1200, 1, 100, 365));
    }

    // ── Quote ────────────────────────────────────────────────────────────

    public function test_quote_shows_the_formula_in_plain_words(): void
    {
        $quote = app(SubscriptionAddonService::class)->quote($this->term($this->enterprise()), ['add_accounts' => 2], Carbon::parse('2026-01-15 10:00', 'Asia/Manila'));

        $this->assertTrue($quote['allowed']);
        $this->assertSame(110.0, $quote['amount']);
        $this->assertSame('2 accounts × ₱100/month for the 17 days left until Feb 1', $quote['lines'][0]['label']);
        $this->assertSame(22, $quote['limits_after']['max_user_accounts']);
        // From the next billing: the plan plus both accounts at full price.
        $this->assertSame(3200.0, $quote['next_amount']);
    }

    public function test_quote_prices_branches_and_accounts_on_their_own_lines(): void
    {
        $quote = app(SubscriptionAddonService::class)->quote($this->term($this->enterprise()), ['add_accounts' => 1, 'add_branches' => 1], Carbon::parse('2026-01-15', 'Asia/Manila'));

        // 1 × ₱500 × 17 ÷ 31 = ₱274.19 → ₱274; 1 × ₱100 → ₱55
        $this->assertSame([274.0, 55.0], array_column($quote['lines'], 'amount'));
        $this->assertSame(329.0, $quote['amount']);
        $this->assertStringStartsWith('1 branch × ₱500/month', $quote['lines'][0]['label']);
    }

    public function test_a_plan_without_an_extra_price_does_not_sell_that_slot(): void
    {
        $service = app(SubscriptionAddonService::class);
        $plan = $this->enterprise(['extra_branch_price' => null]);

        $quote = $service->quote($this->term($plan), ['add_branches' => 1], Carbon::parse('2026-01-15', 'Asia/Manila'));
        $this->assertFalse($quote['allowed']);
        $this->assertSame("The Enterprise plan doesn't offer extra branches.", $quote['blocked_reason']);

        $offer = $service->offer($this->term($plan), Carbon::parse('2026-01-15', 'Asia/Manila'));
        $this->assertTrue($offer['available']);
        $this->assertNull($offer['branch']);
        $this->assertSame(100.0, $offer['account']['monthly_price']);

        $none = $service->offer($this->term($this->enterprise(['extra_branch_price' => null, 'extra_account_price' => null])), Carbon::parse('2026-01-15', 'Asia/Manila'));
        $this->assertFalse($none['available']);
    }

    public function test_an_ended_plan_or_a_free_trial_cannot_take_slots(): void
    {
        $service = app(SubscriptionAddonService::class);
        $ended = $service->quote($this->term($this->enterprise()), ['add_accounts' => 1], Carbon::parse('2026-02-03', 'Asia/Manila'));
        $this->assertFalse($ended['allowed']);

        $trial = $service->quote($this->term($this->enterprise(), overrides: ['is_trial' => true]), ['add_accounts' => 1], Carbon::parse('2026-01-15', 'Asia/Manila'));
        $this->assertFalse($trial['allowed']);
    }

    public function test_asking_for_nothing_is_refused(): void
    {
        $quote = app(SubscriptionAddonService::class)->quote($this->term($this->enterprise()), [], Carbon::parse('2026-01-15', 'Asia/Manila'));
        $this->assertFalse($quote['allowed']);
        $this->assertSame('Enter how many to add.', $quote['blocked_reason']);
    }

    // ── Renewal amount ───────────────────────────────────────────────────

    public function test_the_next_billing_is_the_plan_plus_every_slot_at_full_price(): void
    {
        $plan = $this->enterprise();
        $this->assertSame(3100.0, PlanTerms::renewalAmount($plan, 'Monthly', ['extra_accounts' => 1]));
        $this->assertSame(3700.0, PlanTerms::renewalAmount($plan, 'Monthly', ['extra_accounts' => 2, 'extra_branches' => 1]));
        // Yearly: ₱30,000 + 1 account × ₱100 × 12
        $this->assertSame(31200.0, PlanTerms::renewalAmount($plan, 'Yearly', ['extra_accounts' => 1]));

        $next = app(SubscriptionAddonService::class)->nextBilling($this->term($plan, overrides: ['extra_accounts' => 1]));
        $this->assertSame(3100.0, $next['amount']);
        $this->assertSame(1, $next['extra_accounts']);
    }

    public function test_a_next_plan_without_add_ons_drops_those_slots_instead_of_failing(): void
    {
        $basic = $this->enterprise(['id' => 1, 'name' => 'Basic', 'monthly_price' => 999, 'extra_account_price' => null, 'extra_branch_price' => null]);
        $this->assertSame(['extra_branches' => 0, 'extra_accounts' => 0], PlanTerms::carryOver($basic, ['extra_branches' => 1, 'extra_accounts' => 3]));
        $this->assertSame(999.0, PlanTerms::renewalAmount($basic, 'Monthly', PlanTerms::carryOver($basic, ['extra_accounts' => 3])));
    }

    public function test_a_plan_change_says_which_slots_end(): void
    {
        $sub = $this->term($this->enterprise(), overrides: ['extra_accounts' => 2]);
        $basic = $this->enterprise(['id' => 1, 'name' => 'Basic', 'extra_account_price' => null]);

        $this->assertSame(
            ['The Basic plan has no extra accounts, so your 2 extra accounts end on February 1, 2026.'],
            PlanSwitchService::extrasEnding($sub, $basic, ['extra_accounts' => 0], false),
        );
        $this->assertSame([], PlanSwitchService::extrasEnding($sub, $this->enterprise(['id' => 4]), ['extra_accounts' => 2], true));
    }

    // ── Applying a paid purchase ─────────────────────────────────────────

    public function test_paid_slots_apply_now_and_raise_the_limits(): void
    {
        config(['database.default' => 'addon_slots', 'database.connections.addon_slots' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('addon_slots');
        // Read when a subscription is saved (cache invalidation, grace days).
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('subscription_grace_period_days')->default(7);
            $table->boolean('almost_due_notify_enabled')->default(false);
            $table->integer('almost_due_notify_days_before')->default(3);
            $table->boolean('almost_due_repeat_enabled')->default(false);
            $table->integer('almost_due_repeat_every_days')->default(1);
            $table->boolean('plan_changes_enabled')->default(true);
            $table->integer('plan_change_grace_days')->default(7);
            $table->timestamps();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('name');
            $t->string('category');
            $t->decimal('monthly_price')->nullable();
            $t->decimal('yearly_price')->nullable();
            $t->integer('max_branches');
            $t->integer('max_user_accounts');
            $t->decimal('extra_branch_price')->nullable();
            $t->decimal('extra_account_price')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->integer('spa_business_id');
            $t->integer('subscription_plan_id');
            $t->integer('scheduled_plan_id')->nullable();
            $t->string('billing_cycle')->nullable();
            $t->string('status');
            $t->boolean('auto_renew')->default(false);
            $t->boolean('is_trial')->default(false);
            $t->integer('extra_branches')->default(0);
            $t->integer('extra_accounts')->default(0);
            $t->decimal('extras_paid')->default(0);
            $t->json('scheduled_extras')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $plan = $this->enterprise();
        $plan->exists = false;
        $plan->save();
        $basic = $this->enterprise(['id' => 1, 'name' => 'Basic', 'category' => 'Basic', 'extra_branch_price' => null]);
        $basic->save();
        $sub = $this->term($plan, overrides: ['extra_accounts' => 1, 'extras_paid' => 100, 'scheduled_plan_id' => 1, 'scheduled_extras' => ['extra_branches' => 0, 'extra_accounts' => 0]]);
        $sub->save();

        $applied = app(SubscriptionAddonService::class)->apply($sub, ['add_accounts' => 2, 'add_branches' => 1]);

        $this->assertSame(3, $applied->extra_accounts);
        $this->assertSame(1, $applied->extra_branches);
        $this->assertSame(23, PlanTerms::effective($applied->plan, $applied->only(['extra_branches', 'extra_accounts']))->max_user_accounts);
        // Counted as paid for this term (₱500 + 3 × ₱100), so an upgrade later
        // in the term doesn't charge them again.
        $this->assertEquals(800, $applied->extras_paid);
        // The change already scheduled to Basic keeps the accounts (Basic sells
        // them) but not the branch (it doesn't).
        $this->assertSame(['extra_branches' => 0, 'extra_accounts' => 2], $applied->fresh()->scheduled_extras);
    }
}
