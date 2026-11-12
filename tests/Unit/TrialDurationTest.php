<?php

namespace Tests\Unit;

use App\Http\Requests\SubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\AuditLog;
use App\Models\SubscriptionPlan;
use App\Repository\AuditLogRepository;
use App\Repository\System\SubscriptionPlanRepository;
use App\Service\PlanChangeService;
use App\Service\System\SubscriptionPlanService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\TestCase;

class TrialDurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'trial_duration_test', 'database.connections.trial_duration_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'cache.default' => 'array', 'trial.days' => 14, 'trial.enabled' => true, 'trial.plan_category' => 'Trial']);
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id(); $table->string('uuid')->unique();
            foreach (['category', 'name', 'billing_cycle', 'description', 'export_mode'] as $field) $table->string($field)->nullable();
            foreach (['monthly_price', 'yearly_price', 'extra_branch_price', 'extra_account_price'] as $field) $table->decimal($field)->nullable();
            foreach (['max_branches', 'max_user_accounts', 'export_days', 'trial_days'] as $field) $table->integer($field)->nullable();
            foreach (['package_access', 'reward_access', 'report_access', 'report_export', 'mobile_app_access', 'is_active'] as $field) $table->boolean($field)->default(false);
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id(); $table->integer('subscription_plan_id'); $table->integer('pending_plan_id')->nullable(); $table->integer('scheduled_plan_id')->nullable();
            $table->string('status')->default('Active'); $table->boolean('is_trial')->default(true);
            $table->timestamp('expires_at')->nullable(); $table->timestamps(); $table->softDeletes();
        });
    }

    private function plan(int $days = 7): SubscriptionPlan
    {
        return SubscriptionPlan::create(['category' => 'Trial', 'name' => 'Flexible Trial', 'description' => 'Current trial', 'trial_days' => $days,
            'monthly_price' => 0, 'billing_cycle' => 'Monthly', 'max_branches' => 1, 'max_user_accounts' => 2, 'is_active' => true]);
    }

    private function service(): SubscriptionPlanService
    {
        $audit = Mockery::mock(AuditLogRepository::class)->makePartial();
        $audit->shouldReceive('recordAdminAction')->andReturn(new AuditLog());
        return new SubscriptionPlanService(new SubscriptionPlanRepository(), Mockery::mock(PlanChangeService::class), $audit);
    }

    public function test_resource_uses_saved_days_and_retains_legacy_fallback(): void
    {
        $plan = $this->plan();
        $this->assertSame(7, (new SubscriptionPlanResource($plan))->resolve(Request::create('/'))['trial_days']);
        $plan->trial_days = null;
        $this->assertSame(14, $plan->trialDays());
        $plan->category = 'Basic';
        $this->assertSame(0, $plan->trialDays());
    }

    public function test_only_whole_days_from_one_to_365_are_accepted_for_trial_plans(): void
    {
        $rules = (new SubscriptionPlanRequest())->rules();
        foreach ([0, -1, 1.5, 366, '', null] as $value) {
            $this->assertTrue(Validator::make(['category' => 'Trial', 'trial_days' => $value], ['trial_days' => $rules['trial_days']])->fails());
        }
        foreach ([1, 7, 21, 365] as $value) {
            $this->assertFalse(Validator::make(['category' => 'Trial', 'trial_days' => $value], ['trial_days' => $rules['trial_days']])->fails());
        }
        $this->assertArrayNotHasKey('trial_days', Validator::make(['category' => 'Basic', 'trial_days' => 0], ['trial_days' => $rules['trial_days']])->validated());
    }

    public function test_edit_versions_trial_days_and_keeps_existing_subscription_expiry(): void
    {
        $plan = $this->plan(14);
        $expiry = now()->addDays(9)->toDateTimeString();
        $id = DB::table('subscriptions')->insertGetId(['subscription_plan_id' => $plan->id, 'expires_at' => $expiry]);
        $resource = $this->service()->updateSubscriptionPlan($plan->uuid, ['trial_days' => 7]);
        $new = $resource->resource;
        $this->assertNotSame($plan->id, $new->id);
        $this->assertSame(7, $new->trialDays());
        $this->assertSame(14, $plan->fresh()->trialDays());
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame($expiry, DB::table('subscriptions')->where('id', $id)->value('expires_at'));
        $this->assertSame($plan->id, DB::table('subscriptions')->where('id', $id)->value('subscription_plan_id'));
        $public = (new SubscriptionPlanRepository())->paginateActivePlan();
        $this->assertSame([$new->id], $public->pluck('id')->all());
        $this->assertSame(7, (new SubscriptionPlanResource($public->first()))->resolve(Request::create('/'))['trial_days']);
    }

    public function test_an_unsubscribed_trial_updates_in_place_and_remains_free(): void
    {
        $plan = $this->plan(14);
        $saved = $this->service()->updateSubscriptionPlan($plan->uuid, ['trial_days' => 21, 'monthly_price' => 900])->resource;
        $this->assertSame($plan->id, $saved->id);
        $this->assertSame(21, $saved->trialDays());
        $this->assertSame('0.00', $saved->monthly_price);
    }

    public function test_public_catalog_cache_is_invalidated_when_admin_saves_plan_details(): void
    {
        $plan = $this->plan(14);
        $service = $this->service();
        $before = $service->listOfActiveSubscriptionPlan()->getData(true);
        $this->assertSame(14, $before['data'][0]['trial_days']);
        $service->updateSubscriptionPlan($plan->uuid, ['trial_days' => 7, 'name' => 'Seven Day Trial', 'description' => 'Updated by administrator', 'max_user_accounts' => 4, 'package_access' => true]);
        $after = $service->listOfActiveSubscriptionPlan()->getData(true);
        $this->assertSame(7, $after['data'][0]['trial_days']);
        $this->assertSame('Seven Day Trial', $after['data'][0]['name']);
        $this->assertSame('Updated by administrator', $after['data'][0]['description']);
        $this->assertSame(4, $after['data'][0]['max_user_accounts']);
        $this->assertTrue($after['data'][0]['package_access']);
    }

    public function test_start_trial_calculates_expiry_from_saved_days_without_a_project_database(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['cache.stores.durable' => ['driver' => 'array']]);
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('uuid'); $table->string('role'); $table->string('audience');
            $table->string('email'); $table->timestamp('trial_started_at')->nullable(); $table->timestamps();
        });
        Schema::create('spa_businesses', function (Blueprint $table) {
            $table->id(); $table->integer('owner_id'); $table->softDeletes();
        });
        Schema::table('subscriptions', fn (Blueprint $table) => $table->integer('spa_business_id')->nullable());
        $plan = $this->plan(7);
        $owner = \App\Models\User::create(['role' => 'business_owner', 'email' => 'trial@example.test']);
        DB::table('spa_businesses')->insert(['id' => 1, 'owner_id' => $owner->id]);
        $business = (new \App\Models\SpaBusiness())->forceFill(['id' => 1, 'owner_id' => $owner->id, 'verification_status' => 'Verified']);
        $businesses = Mockery::mock(\App\Repository\SpaBusinessRepository::class);
        $businesses->shouldReceive('findByOwnerId')->with($owner->id)->andReturn($business);
        $subscriptions = Mockery::mock(\App\Repository\SubscriptionRepository::class);
        $subscriptions->shouldReceive('hasAnyForBusiness')->with(1)->andReturn(false);
        $captured = null;
        $subscriptions->shouldReceive('create')->once()->andReturnUsing(function ($payload) use (&$captured) {
            $captured = $payload;
            return (new \App\Models\Subscription())->forceFill(['id' => 1, ...$payload]);
        });
        // Exercise startTrial itself; unrelated payment/checkout dependencies are unused.
        $reflection = new \ReflectionClass(\App\Service\SubscriptionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('businessRepository')->setValue($service, $businesses);
        $reflection->getProperty('subscriptionRepository')->setValue($service, $subscriptions);
        $response = $service->startTrial($owner);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($plan->id, $captured['subscription_plan_id']);
        $this->assertTrue($captured['expires_at']->equalTo($captured['starts_at']->copy()->addDays(7)));
        $this->assertNotNull($owner->fresh()->trial_started_at);
        $this->assertSame(7, $response->getData(true)['trial']['days']);
    }

    public function test_migration_retains_legacy_offer_and_expiry_and_can_roll_back(): void
    {
        $plan = $this->plan(14);
        $expiry = now()->addDays(5)->toDateTimeString();
        DB::table('subscriptions')->insert(['subscription_plan_id' => $plan->id, 'expires_at' => $expiry]);
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn('trial_days'));
        $migration = require base_path('database/migrations/2026_11_18_090000_add_trial_days_to_subscription_plans.php');
        $migration->up();
        $this->assertSame(14, $plan->fresh()->trial_days);
        $this->assertSame($expiry, DB::table('subscriptions')->value('expires_at'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('subscription_plans', 'trial_days'));
    }
}
