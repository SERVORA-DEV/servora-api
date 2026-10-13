<?php

namespace Tests\Feature;

use App\Models\CustomerProgram;
use App\Models\OwnerIdentityVerification;
use App\Models\ProgramRequest;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A manager asks the owner for a new voucher, discount or membership for
// their branch; the owner approves it (as asked or changed first) or
// declines, and an approved one is offered at the branch that asked, and only
// there until the owner offers it somewhere else (ProgramRequestService).
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=ProgramRequestTest
class ProgramRequestTest extends TestCase
{
    use RefreshDatabase;

    private const DISCOUNT = ['type' => 'discount', 'name' => 'Student Rate', 'audience' => 'all', 'values' => ['kind' => '%', 'percent' => '10']];

    private User $owner;
    private User $manager;
    private User $otherManager;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private SpaBranch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $this->otherBranch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        // A live plan that includes customer programs, switched on.
        $plan = SubscriptionPlan::create([
            'category' => 'Premium', 'name' => 'Premium', 'monthly_price' => 1500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true, 'reward_access' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/programs/module', ['enabled' => true])->assertSuccessful();

        $this->manager = $this->managerAt($this->branch, 'managerone');
        $this->otherManager = $this->managerAt($this->otherBranch, 'managertwo');
    }

    // A manager account, made the way the owner makes one.
    private function managerAt(SpaBranch $branch, string $username): User
    {
        $staff = Staff::factory()->create(['spa_branch_id' => $branch->id, 'role' => 'manager']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/account', [
            'staff_uuid' => $staff->uuid,
            'username' => $username,
            'email' => "{$username}@lotus.test",
            'password' => 'secret-password',
        ])->assertSuccessful();

        return User::where('username', $username)->firstOrFail();
    }

    private function ask(array $body = [])
    {
        Sanctum::actingAs($this->manager);

        return $this->postJson('/api/business/program-requests', array_replace_recursive(self::DISCOUNT, $body));
    }

    private function approve(string $uuid, array $changed = [])
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson("/api/business/program-requests/{$uuid}/approve", $changed);
    }

    public function test_a_manager_asks_and_the_owner_is_told(): void
    {
        $this->ask(['note' => 'Lots of students nearby.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by_me', true)
            ->assertJsonPath('data.program.values.percent', '10');

        $this->assertDatabaseHas('notifications', ['user_id' => $this->owner->id, 'title' => 'New Discount Request']);
        // Nothing is created until the owner says yes.
        $this->assertSame(0, CustomerProgram::count());

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/business/program-requests')->assertOk()->assertJsonPath('available', true)->assertJsonCount(1, 'data');
    }

    public function test_only_a_manager_asks_and_not_for_loyalty(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/program-requests', self::DISCOUNT)->assertForbidden();

        $this->ask(['type' => 'loyalty'])->assertStatus(422);
    }

    public function test_a_request_is_held_to_the_rules_of_the_owners_own_save(): void
    {
        $this->ask(['values' => ['percent' => '']])->assertStatus(422);
        $this->ask(['values' => ['percent' => '140']])->assertStatus(422);

        $this->assertSame(0, ProgramRequest::count());
    }

    public function test_a_name_already_used_or_already_asked_for_is_refused(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/programs', ['type' => 'discount', 'name' => 'Senior Citizen', 'values' => ['kind' => '%', 'percent' => '20']])->assertSuccessful();

        $this->ask(['name' => 'senior citizen'])->assertStatus(422);
        $this->ask()->assertCreated();
        $this->ask(['name' => 'STUDENT  RATE'])->assertStatus(422);

        $this->assertSame(1, ProgramRequest::count());
    }

    public function test_approving_creates_it_for_the_branch_that_asked_only(): void
    {
        $uuid = $this->ask()->json('data.uuid');

        // A manager can't approve, not even their own.
        $this->postJson("/api/business/program-requests/{$uuid}/approve")->assertForbidden();

        $programUuid = $this->approve($uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('program.only_branch.uuid', $this->branch->uuid)
            ->json('program.uuid');

        $program = CustomerProgram::where('uuid', $programUuid)->firstOrFail();
        $this->assertTrue($program->is_active);
        $this->assertSame([$this->branch->id], $program->branches()->pluck('spa_branches.id')->all());
        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->id, 'title' => 'Discount Request Approved']);

        // Answered once only.
        $this->approve($uuid)->assertStatus(422);
    }

    public function test_another_branch_cannot_see_it_or_switch_it_on_until_the_owner_offers_it_there(): void
    {
        $programUuid = $this->approve($this->ask()->json('data.uuid'))->json('program.uuid');
        $switchOn = fn () => $this->putJson("/api/business/programs/{$programUuid}/branches/{$this->otherBranch->uuid}", ['offered' => true]);

        Sanctum::actingAs($this->otherManager);
        $this->getJson('/api/business/programs')->assertOk()->assertJsonMissing(['uuid' => $programUuid]);
        $switchOn()->assertStatus(422);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/business/programs')->assertOk()->assertJsonFragment(['uuid' => $programUuid]);

        // The owner offering it at the other branch makes it an ordinary program.
        Sanctum::actingAs($this->owner);
        $switchOn()->assertOk();
        $this->assertNull(CustomerProgram::where('uuid', $programUuid)->value('only_branch_id'));

        Sanctum::actingAs($this->otherManager);
        $this->getJson('/api/business/programs')->assertOk()->assertJsonFragment(['uuid' => $programUuid]);
        $this->putJson("/api/business/programs/{$programUuid}/branches/{$this->otherBranch->uuid}", ['offered' => false])->assertOk();
        $switchOn()->assertOk();
    }

    public function test_the_owner_can_change_it_first(): void
    {
        $uuid = $this->ask()->json('data.uuid');

        $programUuid = $this->approve($uuid, ['name' => 'Student Rate 15', 'audience' => 'all', 'values' => ['kind' => '%', 'percent' => '15']])
            ->assertOk()->json('program.uuid');

        $program = CustomerProgram::where('uuid', $programUuid)->firstOrFail();
        $this->assertSame('Student Rate 15', $program->name);
        $this->assertSame('15', $program->value('percent'));
        $this->assertSame($this->branch->id, $program->only_branch_id);
    }

    public function test_declining_tells_the_manager_why_and_creates_nothing(): void
    {
        $uuid = $this->ask()->json('data.uuid');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/business/program-requests/{$uuid}/decline", ['reason' => 'Not this year.'])->assertOk();

        $this->assertSame(0, CustomerProgram::count());
        $this->assertDatabaseHas('notifications', ['user_id' => $this->manager->id, 'title' => 'Discount Request Declined']);

        // The answer stays on the manager's list; the owner's list is empty.
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/business/program-requests')->assertJsonPath('data.0.status', 'declined')->assertJsonPath('data.0.decision_note', 'Not this year.');
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/business/program-requests')->assertJsonCount(0, 'data');
    }

    public function test_only_the_branch_that_asked_withdraws(): void
    {
        $uuid = $this->ask()->json('data.uuid');

        Sanctum::actingAs($this->otherManager);
        $this->getJson('/api/business/program-requests')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/business/program-requests/{$uuid}")->assertStatus(422);

        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/business/program-requests/{$uuid}")->assertOk();
        $this->approve($uuid)->assertStatus(422);
    }

    public function test_a_branch_can_only_have_so_many_waiting(): void
    {
        foreach (range(1, 10) as $i) {
            $this->ask(['name' => "Request {$i}"])->assertCreated();
        }

        $this->ask(['name' => 'One too many'])->assertStatus(422);
    }
}
