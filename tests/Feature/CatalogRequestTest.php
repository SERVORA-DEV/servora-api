<?php

namespace Tests\Feature;

use App\Models\CatalogRequest;
use App\Models\OwnerIdentityVerification;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A manager asks the owner for a service or package the catalog doesn't have;
// the owner adds it or declines, and each side is told (CatalogRequestService).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=CatalogRequestTest
class CatalogRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        // The dashboard routes need a live plan.
        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);

        // A manager account, made the way the owner makes one.
        $managerStaff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'manager']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/account', [
            'staff_uuid' => $managerStaff->uuid,
            'username' => 'branchmanager',
            'email' => 'manager@lotus.test',
            'password' => 'secret-password',
        ])->assertSuccessful();
        $this->manager = User::where('username', 'branchmanager')->firstOrFail();
    }

    private function ask(array $body = [])
    {
        Sanctum::actingAs($this->manager);

        return $this->postJson('/api/business/catalog-requests', $body + ['kind' => 'service', 'name' => 'Bamboo Massage']);
    }

    public function test_a_manager_asks_and_the_owner_is_told(): void
    {
        $this->ask(['category' => 'Massage', 'details' => 'Clients ask weekly.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by_me', true);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->owner->id, 'title' => 'New Service Request']);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/business/catalog-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_owner_does_not_send_requests(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/catalog-requests', ['kind' => 'service', 'name' => 'Bamboo Massage'])->assertForbidden();
    }

    public function test_something_already_in_the_catalog_or_already_asked_for_is_refused(): void
    {
        Service::create(['spa_business_id' => $this->business->id, 'created_by' => $this->owner->id, 'name' => 'Swedish Massage', 'is_active' => true]);

        $this->ask(['name' => 'swedish massage'])->assertStatus(422);
        $this->ask()->assertCreated();
        $this->ask(['name' => 'BAMBOO  MASSAGE'])->assertStatus(422);

        $this->assertSame(1, CatalogRequest::count());
    }

    public function test_only_the_owner_answers_and_the_manager_is_told(): void
    {
        $added = $this->ask()->json('data.uuid');
        $declined = $this->ask(['kind' => 'package', 'name' => 'Couples Retreat'])->json('data.uuid');

        $this->postJson("/api/business/catalog-requests/{$added}/added")->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/business/catalog-requests/{$added}/added")->assertOk()->assertJsonPath('data.status', 'added');
        $this->postJson("/api/business/catalog-requests/{$declined}/decline", ['reason' => 'Not this year.'])->assertOk();
        // Answered once only.
        $this->postJson("/api/business/catalog-requests/{$added}/decline")->assertStatus(422);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->id, 'title' => 'Service Request Added']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->id, 'title' => 'Package Request Declined']);

        // Nothing left waiting for the owner; the manager still reads the answers.
        $this->getJson('/api/business/catalog-requests')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/business/catalog-requests')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_manager_withdraws_their_own_request(): void
    {
        $uuid = $this->ask()->json('data.uuid');

        $this->deleteJson("/api/business/catalog-requests/{$uuid}")->assertOk();
        $this->assertSame('withdrawn', CatalogRequest::firstOrFail()->status);
        $this->getJson('/api/business/catalog-requests')->assertOk()->assertJsonCount(0, 'data');
    }
}
