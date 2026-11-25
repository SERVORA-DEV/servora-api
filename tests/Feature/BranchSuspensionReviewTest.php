<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminTwoFactor;
use App\Http\Middleware\RestrictAdminIp;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Service\Uploads\DocumentUploadService;
use App\Support\Platform\Suspension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class BranchSuspensionReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RequireAdminTwoFactor::class, RestrictAdminIp::class]);
        $this->admin = User::factory()->create(['role' => 'system_administrator']);
        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'latitude' => 7.0731,
            'longitude' => 125.6128,
            'formatted_address' => 'Davao City',
            'permit_document_path' => 'permits/old',
            'permit_number' => 'BP-2026-123',
            'permit_business_name' => $this->business->business_name,
            'permit_branch_location' => 'Davao City',
            'permit_issue_date' => '2026-01-01',
            'permit_confirmed' => true,
        ]);
    }

    private function verifyRoute(string $action): string
    {
        return "/api/system/branch-registrations/{$this->branch->uuid}/{$action}";
    }

    private function ownerRoute(string $action): string
    {
        return "/api/business/branch/{$this->branch->uuid}/{$action}";
    }

    public function test_only_branch_review_can_lift_a_suspension_after_owner_resubmits(): void
    {
        $uploads = Mockery::mock(DocumentUploadService::class);
        $uploads->shouldReceive('signedUrl')->andReturn(null);
        $this->app->instance(DocumentUploadService::class, $uploads);
        $sibling = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/system/businesses/{$this->business->uuid}/suspend", ['reason' => 'Check permit'])->assertNotFound();
        $this->postJson("/api/system/branches/{$this->branch->uuid}/suspend", ['reason' => 'Check permit'])->assertOk()
            ->assertJsonPath('data.verification_status', 'Suspended');
        $this->postJson("/api/system/branches/{$this->branch->uuid}/reactivate")->assertNotFound();
        $this->postJson($this->verifyRoute('approve'))->assertUnprocessable();
        $this->assertSame('Verified', $this->business->fresh()->verification_status);
        $this->assertFalse(Suspension::isSuspended($sibling->fresh()));

        Sanctum::actingAs(User::factory()->create(['role' => 'business_owner']));
        $this->postJson($this->ownerRoute('submit'))->assertUnprocessable();

        Sanctum::actingAs($this->owner);
        $this->postJson($this->ownerRoute('submit'))->assertOk()
            ->assertJsonPath('data.verification_status', 'Pending')
            ->assertJsonPath('data.suspension_reason', 'Check permit');
        $this->postJson($this->ownerRoute('submit'))->assertUnprocessable();
        $this->assertSame('permits/old', $this->branch->fresh()->permit_document_path);
        $this->assertSame(422, Suspension::refusal($this->branch->fresh())?->getStatusCode());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/system/branch-registrations')->assertOk()
            ->assertJsonFragment(['uuid' => $this->branch->uuid, 'suspension_reason' => 'Check permit']);
        $this->postJson($this->verifyRoute('reject'), ['reason' => 'Send the latest permit'])->assertOk();
        $this->assertTrue(Suspension::isSuspended($this->branch->fresh()));

        Sanctum::actingAs($this->owner);
        $uploads->shouldReceive('store')->once()->andReturn('permits/new');
        $uploads->shouldReceive('delete')->once()->with('permits/old');
        $this->post($this->ownerRoute('permit'), [
            'permit_document' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf'),
            'permit_number' => 'BP-2026-456',
            'permit_business_name' => $this->business->business_name,
            'permit_branch_location' => 'Davao City',
            'permit_issue_date' => '2026-01-02',
            'permit_confirmed' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($this->ownerRoute('submit'))->assertOk();
        $this->assertSame('permits/new', $this->branch->fresh()->permit_document_path);

        Sanctum::actingAs($this->admin);
        $this->postJson($this->verifyRoute('approve'))->assertOk()
            ->assertJsonPath('data.verification_status', 'Verified')
            ->assertJsonPath('data.suspension_reason', null);
        $this->assertNull(Suspension::refusal($this->branch->fresh()));
    }

    public function test_migration_restores_only_legacy_business_suspensions(): void
    {
        $this->business->update(['verification_status' => 'Suspended', 'suspension_reason' => 'Old business suspension']);
        $this->branch->update(['verification_status' => 'Suspended', 'suspension_reason' => 'Branch suspension']);
        $pending = SpaBusiness::factory()->create(['verification_status' => 'Pending']);

        $migration = require database_path('migrations/2026_11_25_000000_restore_suspended_spa_businesses.php');
        $migration->up();
        $migration->up();

        $this->assertSame('Verified', $this->business->fresh()->verification_status);
        $this->assertNull($this->business->fresh()->suspension_reason);
        $this->assertSame('Pending', $pending->fresh()->verification_status);
        $this->assertSame('Suspended', $this->branch->fresh()->verification_status);
        $this->assertSame('Branch suspension', $this->branch->fresh()->suspension_reason);
    }
}
