<?php

namespace Tests\Unit;

use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\CustomerProgram;
use App\Models\Facility;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Service\Business\BranchCloneService;
use App\Service\Business\SpaBranchService;
use App\Service\PlanFitService;
use App\Support\AppCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Actual clone/create services and HTTP validation against a disposable SQLite
// fixture. PostgreSQL's row-lock concurrency still needs integration coverage.
class BranchCloneTest extends TestCase
{
    private User $owner;

    private SpaBusiness $business;

    private SpaBranch $source;

    private SubscriptionPlan $plan;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'branch_clone_test', 'database.connections.branch_clone_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'cache.default' => 'array']);
        DB::purge('branch_clone_test');
        $this->withoutMiddleware();
        $this->schema();
        DB::table('system_settings')->insert(['subscription_grace_period_days' => 7]);
        $this->owner = (new User)->forceFill(['id' => 1, 'role' => 'business_owner']);
        Sanctum::actingAs($this->owner);
        $this->business = SpaBusiness::create(['owner_id' => 1, 'business_name' => 'Test Spa']);
        $this->source = SpaBranch::create([
            'spa_business_id' => $this->business->id, 'branch_name' => 'Original', 'code' => 'OLD',
            'verification_status' => 'Verified', 'operating_status' => 'Active',
            'latitude' => 7.1, 'longitude' => 125.6, 'permit_number' => 'SOURCE-ONLY',
            'booking_overrides' => ['allow_cancellation' => false],
        ]);
        $this->plan = SubscriptionPlan::create(['name' => 'Purchased terms', 'category' => 'Enterprise', 'max_branches' => 2, 'max_user_accounts' => 30]);
        $this->subscription = Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $this->plan->id,
            'status' => 'Active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
            'extra_branches' => 0, 'extra_accounts' => 0,
        ]);
    }

    private function schema(): void
    {
        Schema::create('system_settings', fn (Blueprint $t) => [$t->id(), $t->integer('subscription_grace_period_days')]);
        Schema::create('spa_businesses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('owner_id');
            $t->string('business_name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('spa_branches', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->string('branch_name');
            foreach (['code', 'description', 'verification_status', 'operating_status', 'permit_number'] as $column) {
                $t->string($column)->nullable();
            }
            $t->decimal('latitude')->nullable();
            $t->decimal('longitude')->nullable();
            $t->json('booking_overrides')->nullable();
            $t->timestamp('plan_hold_at')->nullable();
            $t->boolean('listing_visible')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['spa_business_id', 'code']);
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->string('name');
            $t->string('category');
            $t->integer('max_branches');
            $t->integer('max_user_accounts');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->integer('subscription_plan_id');
            $t->string('status');
            $t->string('plan_change_status')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->integer('extra_branches')->default(0);
            $t->integer('extra_accounts')->default(0);
            $t->timestamp('starts_at');
            $t->timestamp('expires_at');
            $t->json('scheduled_keep')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('users', fn (Blueprint $t) => [$t->id(), $t->timestamp('plan_hold_at')->nullable()]);
        Schema::create('spa_branch_photos', function (Blueprint $t) {
            $t->id();
            $t->integer('spa_branch_id');
            $t->boolean('is_cover');
            $t->string('path');
            $t->timestamps();
        });
        Schema::create('branch_schedules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_branch_id');
            $t->string('day_of_week');
            $t->time('opening_time')->nullable();
            $t->time('closing_time')->nullable();
            $t->boolean('is_closed');
            $t->timestamps();
        });
        Schema::create('facilities', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_branch_id');
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('category')->nullable();
            $t->string('status');
            $t->boolean('is_available');
            $t->json('amenities')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('facility_services', fn (Blueprint $t) => [$t->integer('facility_id'), $t->integer('service_id')]);
        foreach (['services', 'packages'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->integer('spa_business_id');
                $t->softDeletes();
            });
        }
        Schema::create('service_variants', function (Blueprint $t) {
            $t->id();
            $t->integer('service_id');
            $t->softDeletes();
        });
        foreach (['branch_services' => 'service_variant_id', 'branch_packages' => 'package_id'] as $table => $key) {
            Schema::create($table, function (Blueprint $t) use ($key) {
                $t->id();
                $t->integer('spa_branch_id');
                $t->integer($key);
                $t->decimal('custom_price')->nullable();
                $t->boolean('is_available');
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::create('customer_programs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->string('name');
            $t->integer('only_branch_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('customer_program_branch', function (Blueprint $t) {
            $t->integer('customer_program_id');
            $t->integer('spa_branch_id');
            $t->timestamps();
            $t->unique(['customer_program_id', 'spa_branch_id']);
        });
    }

    private function payload(string $name = 'Copy', array $copy = []): array
    {
        return ['branch' => ['branch_name' => $name, 'code' => 'NEW-'.$name], 'copy' => array_merge([
            'facilities' => true, 'offerings' => true, 'hours' => true, 'booking_rules' => true, 'programs' => true,
        ], $copy), 'facility_names' => []];
    }

    private function url(?SpaBranch $source = null): string
    {
        return '/api/business/branch/'.($source ?? $this->source)->uuid.'/clone';
    }

    private function limit(int $limit, int $extras = 0): void
    {
        $this->plan->update(['max_branches' => $limit]);
        $this->subscription->update(['extra_branches' => $extras]);
        AppCache::forgetSubscription($this->business->id);
    }

    public function test_copy_creates_a_separate_draft_with_selected_configuration(): void
    {
        DB::table('services')->insert(['id' => 1, 'spa_business_id' => $this->business->id]);
        DB::table('service_variants')->insert(['id' => 1, 'service_id' => 1]);
        DB::table('packages')->insert(['id' => 1, 'spa_business_id' => $this->business->id]);
        $facility = Facility::create(['spa_branch_id' => $this->source->id, 'name' => 'Room A', 'status' => 'Maintenance', 'is_available' => true, 'amenities' => ['Shower']]);
        $facility->services()->attach(1);
        BranchService::create(['spa_branch_id' => $this->source->id, 'service_variant_id' => 1, 'custom_price' => 650, 'is_available' => true]);
        BranchPackage::create(['spa_branch_id' => $this->source->id, 'package_id' => 1, 'custom_price' => 900, 'is_available' => false]);
        $schedule = $this->source->schedules()->create(['day_of_week' => 'Monday', 'opening_time' => '09:00', 'closing_time' => '18:00', 'is_closed' => false]);
        $program = CustomerProgram::create(['spa_business_id' => $this->business->id, 'name' => 'Member offer', 'only_branch_id' => $this->source->id]);
        $program->branches()->attach($this->source->id);
        $payload = $this->payload();
        $payload['facility_names'][$facility->uuid] = 'New Room';

        $response = $this->postJson($this->url(), $payload)->assertCreated()
            ->assertJsonPath('data.verification_status', 'Unregistered')
            ->assertJsonPath('data.code', 'NEW-Copy')
            ->assertJsonPath('copied.facilities', 1)->assertJsonPath('copied.offerings', 2)
            ->assertJsonPath('copied.hours', 1)->assertJsonPath('copied.programs', 1);
        $target = SpaBranch::where('uuid', $response->json('data.uuid'))->sole();
        $this->assertNotSame($this->source->uuid, $target->uuid);
        $this->assertNull($target->latitude);
        $this->assertNull($target->permit_number);
        $this->assertNull($target->plan_hold_at);
        $this->assertSame(['allow_cancellation' => false], $target->booking_overrides);
        $room = $target->facilities()->sole();
        $this->assertNotSame($facility->uuid, $room->uuid);
        $this->assertSame('New Room', $room->name);
        $this->assertSame('Maintenance', $room->status);
        $this->assertSame(['Shower'], $room->amenities);
        $this->assertSame([1], $room->services()->pluck('services.id')->all());
        $this->assertNotSame($schedule->uuid, $target->schedules()->sole()->uuid);
        $this->assertSame('650.00', $target->branchServices()->sole()->custom_price);
        $this->assertFalse($target->branchPackages()->sole()->is_available);
        $this->assertSame(2, $program->branches()->count());
        $this->assertNull($program->fresh()->only_branch_id);
        $this->assertSame('Room A', $facility->fresh()->name);
        $this->assertSame(2, app(PlanFitService::class)->branches($this->business)->count());
    }

    public function test_copy_uses_the_last_slot_and_further_copies_and_normal_creation_are_rejected(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->postJson($this->url(), $this->payload('Again'))->assertUnprocessable()->assertJsonPath('code', 'PLAN_LIMIT');
        $ordinary = app(SpaBranchService::class)->createSpaBranch($this->owner, ['branch_name' => 'Ordinary']);
        $this->assertSame(422, $ordinary->getStatusCode());
        $this->assertSame(2, SpaBranch::count());
    }

    public function test_purchased_extra_branch_capacity_is_honoured(): void
    {
        $this->limit(1, 1);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->postJson($this->url(), $this->payload('Again'))->assertUnprocessable()->assertJsonPath('code', 'PLAN_LIMIT');
        $this->assertSame(1, $this->plan->fresh()->max_branches);
    }

    public function test_holds_and_deleted_branches_do_not_count_but_inactive_branches_and_drafts_do(): void
    {
        $held = SpaBranch::create(['spa_business_id' => $this->business->id, 'branch_name' => 'Held', 'plan_hold_at' => now()]);
        $deleted = SpaBranch::create(['spa_business_id' => $this->business->id, 'branch_name' => 'Deleted']);
        $deleted->delete();
        $this->source->update(['operating_status' => 'Inactive']);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->postJson($this->url(), $this->payload('Again'))->assertUnprocessable()->assertJsonPath('code', 'PLAN_LIMIT');
        $this->assertNotNull($held->fresh()->plan_hold_at);
    }

    public function test_unlimited_legacy_terms_and_pre_subscription_setup_keep_the_normal_creation_rules(): void
    {
        $this->limit(9999);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->subscription->delete();
        AppCache::forgetSubscription($this->business->id);
        $this->postJson($this->url(), $this->payload('Setup'))->assertCreated();
    }

    public function test_foreign_sources_drafts_non_owners_and_invalid_payloads_are_rejected(): void
    {
        $foreign = SpaBranch::create(['spa_business_id' => 999, 'branch_name' => 'Foreign', 'verification_status' => 'Verified']);
        $this->postJson($this->url($foreign), $this->payload())->assertNotFound();
        $this->source->update(['verification_status' => 'Unregistered']);
        $this->postJson($this->url(), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->source->update(['verification_status' => 'Verified']);
        $invalid = $this->payload();
        $invalid['copy'] = array_fill_keys(array_keys($invalid['copy']), false);
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('copy');
        $invalid = $this->payload();
        $invalid['branch']['code'] = 'OLD';
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('branch.code');
        $invalid = $this->payload();
        $invalid['branch']['spa_business_id'] = 999;
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('branch');
        $invalid = $this->payload();
        $invalid['branch']['code'] = ['invalid'];
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('branch.code');
        Sanctum::actingAs((new User)->forceFill(['id' => 2, 'role' => 'manager']));
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertSame(2, SpaBranch::count());
    }

    public function test_unselected_settings_are_not_copied(): void
    {
        Facility::create(['spa_branch_id' => $this->source->id, 'name' => 'Room', 'status' => 'Available', 'is_available' => true]);
        $response = $this->postJson($this->url(), $this->payload('Hours only', [
            'facilities' => false, 'offerings' => false, 'booking_rules' => false, 'programs' => false,
        ]))->assertCreated();
        $target = SpaBranch::where('uuid', $response->json('data.uuid'))->sole();
        $this->assertSame(0, $target->facilities()->count());
        $this->assertNull($target->booking_overrides);
    }

    public function test_invalid_facility_names_roll_back_the_new_branch_and_any_earlier_copies(): void
    {
        $a = Facility::create(['spa_branch_id' => $this->source->id, 'name' => 'A', 'status' => 'Available', 'is_available' => true]);
        $b = Facility::create(['spa_branch_id' => $this->source->id, 'name' => 'B', 'status' => 'Available', 'is_available' => true]);
        $payload = $this->payload();
        $payload['facility_names'][$b->uuid] = 'a';
        $this->postJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors('facility_names');
        $this->assertSame(1, SpaBranch::count());
        $this->assertSame(2, Facility::count());
        $payload['facility_names'] = ['not-a-source-facility' => 'Other'];
        $this->postJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors('facility_names');
        $this->assertSame(1, SpaBranch::count());
        $this->assertSame('A', $a->fresh()->name);
    }

    public function test_an_unexpected_copy_failure_rolls_back_everything(): void
    {
        Facility::create(['spa_branch_id' => $this->source->id, 'name' => 'Room', 'status' => 'Available', 'is_available' => true]);
        Facility::creating(function (Facility $facility) {
            if ($facility->spa_branch_id !== $this->source->id) {
                throw new \RuntimeException('Copy failed');
            }
        });
        try {
            app(BranchCloneService::class)->clone($this->owner, $this->source->uuid, $this->payload());
            $this->fail('Expected the copy to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Copy failed', $exception->getMessage());
        }
        $this->assertSame(1, SpaBranch::count());
        $this->assertSame(1, Facility::count());
    }
}
