<?php

namespace Tests\Feature;

use App\Models\OwnerIdentityVerification;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Commission is set only in Business Settings → Staff Policies — services,
// service options and packages carry no commission of their own.
class NoItemCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $owner->id, 'status' => 'Verified']);
        SpaBusiness::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($owner);
    }

    public function test_the_per_item_commission_columns_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('service_variants', 'commission_amount'));
        $this->assertFalse(Schema::hasColumn('branch_services', 'custom_commission'));
        $this->assertFalse(Schema::hasColumn('packages', 'default_commission_amount'));
        $this->assertFalse(Schema::hasColumn('branch_packages', 'custom_commission'));
    }

    public function test_a_service_ignores_commission_and_never_returns_it(): void
    {
        $response = $this->postJson('/api/business/service', [
            'name' => 'Swedish Massage',
            'variants' => [['duration_minutes' => 60, 'price' => 900, 'commission_amount' => 100]],
        ])->assertSuccessful();

        $this->assertStringNotContainsString('commission', $response->getContent());
    }

    public function test_a_package_ignores_commission_and_never_returns_it(): void
    {
        $response = $this->postJson('/api/business/package', [
            'name' => 'Relax Duo',
            'default_price' => 1500,
            'default_commission_amount' => 150,
        ])->assertSuccessful();

        $this->assertStringNotContainsString('commission', $response->getContent());
    }
}
