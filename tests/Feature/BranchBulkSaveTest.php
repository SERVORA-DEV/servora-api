<?php

namespace Tests\Feature;

use App\Models\BranchPackage;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\OwnerIdentityVerification;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The two "save everything at once" endpoints behind Branch Settings: the
// branch's whole week of hours, and every service/package it offers. Each
// replaces a request per row, which timed out over a slow connection.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=BranchBulkSaveTest
class BranchBulkSaveTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        // These routes sit behind "verified and subscribed".
        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->owner);
    }

    private function url(string $path, ?SpaBranch $branch = null): string
    {
        return '/api/business/branch/' . ($branch ?? $this->branch)->uuid . $path;
    }

    private function week(string $open = '10:00', string $close = '22:00'): array
    {
        return array_map(fn ($day) => [
            'day_of_week' => $day,
            'opening_time' => $day === 'Sunday' ? null : $open,
            'closing_time' => $day === 'Sunday' ? null : $close,
            'is_closed' => $day === 'Sunday',
        ], self::DAYS);
    }

    private function variant(int $minutes = 60, float $price = 800): ServiceVariant
    {
        $service = Service::factory()->create(['spa_business_id' => $this->business->id, 'created_by' => $this->owner->id]);

        return ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => $minutes, 'price' => $price]);
    }

    // ── Hours ─────────────────────────────────────────────────────────────

    public function test_a_new_branchs_whole_week_saves_in_one_request(): void
    {
        $this->putJson($this->url('/schedule'), ['days' => $this->week()])
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.day_of_week', 'Monday')
            ->assertJsonPath('data.6.is_closed', true);

        $this->assertSame(7, BranchSchedule::where('spa_branch_id', $this->branch->id)->count());
    }

    public function test_saving_again_updates_the_same_days_and_keeps_their_ids(): void
    {
        $this->putJson($this->url('/schedule'), ['days' => $this->week()])->assertOk();
        $monday = BranchSchedule::where('spa_branch_id', $this->branch->id)->where('day_of_week', 'Monday')->sole();

        $this->putJson($this->url('/schedule'), ['days' => $this->week('09:00', '21:00')])->assertOk();

        $this->assertSame(7, BranchSchedule::where('spa_branch_id', $this->branch->id)->count());
        $after = $monday->fresh();
        $this->assertSame($monday->uuid, $after->uuid);
        $this->assertSame('09:00', substr((string) $after->opening_time, 0, 5));
    }

    public function test_overnight_and_24_hour_days_are_accepted(): void
    {
        $days = $this->week();
        $days[0] = ['day_of_week' => 'Monday', 'opening_time' => '22:00', 'closing_time' => '02:00', 'is_closed' => false];
        $days[1] = ['day_of_week' => 'Tuesday', 'opening_time' => '00:00', 'closing_time' => '00:00', 'is_closed' => false];

        $this->putJson($this->url('/schedule'), ['days' => $days])->assertOk();
    }

    public function test_the_same_day_twice_is_refused(): void
    {
        $days = [$this->week()[0], $this->week()[0]];

        $this->putJson($this->url('/schedule'), ['days' => $days])->assertStatus(422);
    }

    public function test_another_businesss_hours_cannot_be_saved(): void
    {
        $foreign = SpaBranch::factory()->create();

        $this->putJson($this->url('/schedule', $foreign), ['days' => $this->week()])->assertNotFound();
        $this->assertSame(0, BranchSchedule::where('spa_branch_id', $foreign->id)->count());
    }

    // ── Services and packages offered ─────────────────────────────────────

    public function test_several_services_and_a_package_save_in_one_request(): void
    {
        $swedish = $this->variant(60, 800);
        $hilot = $this->variant(90, 1150);
        $package = Package::factory()->create(['spa_business_id' => $this->business->id]);

        $this->patchJson($this->url('/offerings'), [
            'services' => [
                ['variant_uuid' => $swedish->uuid, 'is_available' => true, 'custom_price' => 900],
                ['variant_uuid' => $hilot->uuid, 'is_available' => false, 'custom_price' => null],
            ],
            'packages' => [
                ['package_uuid' => $package->uuid, 'is_available' => true, 'custom_price' => null],
            ],
        ])->assertOk()
            ->assertJsonPath('data.branch_uuid', $this->branch->uuid)
            ->assertJsonPath('data.services.0.custom_price', 900.0)
            ->assertJsonPath('data.packages.0.is_available', true);

        $row = BranchService::where('spa_branch_id', $this->branch->id)->where('service_variant_id', $swedish->id)->sole();
        $this->assertTrue($row->is_available);
        $this->assertSame('900.00', $row->custom_price);
        $this->assertFalse(BranchService::where('spa_branch_id', $this->branch->id)->where('service_variant_id', $hilot->id)->sole()->is_available);
        $this->assertTrue(BranchPackage::where('spa_branch_id', $this->branch->id)->where('package_id', $package->id)->sole()->is_available);
    }

    public function test_saving_again_changes_the_same_row(): void
    {
        $swedish = $this->variant();
        $send = fn (bool $on, ?float $price) => $this->patchJson($this->url('/offerings'), [
            'services' => [['variant_uuid' => $swedish->uuid, 'is_available' => $on, 'custom_price' => $price]],
            'packages' => [],
        ])->assertOk();

        $send(true, 900);
        $send(false, null);

        $row = BranchService::where('spa_branch_id', $this->branch->id)->where('service_variant_id', $swedish->id)->sole();
        $this->assertFalse($row->is_available);
        $this->assertNull($row->custom_price);
    }

    public function test_another_businesss_service_is_refused_and_nothing_is_saved(): void
    {
        $mine = $this->variant();
        $foreignService = Service::factory()->create();
        $theirs = ServiceVariant::factory()->create(['service_id' => $foreignService->id]);

        $this->patchJson($this->url('/offerings'), [
            'services' => [
                ['variant_uuid' => $mine->uuid, 'is_available' => true],
                ['variant_uuid' => $theirs->uuid, 'is_available' => true],
            ],
            'packages' => [],
        ])->assertNotFound();

        $this->assertSame(0, BranchService::where('spa_branch_id', $this->branch->id)->where('service_variant_id', $theirs->id)->count());
    }

    public function test_another_businesss_branch_is_not_found(): void
    {
        $foreign = SpaBranch::factory()->create();

        $this->patchJson($this->url('/offerings', $foreign), ['services' => [], 'packages' => []])->assertNotFound();
    }
}
