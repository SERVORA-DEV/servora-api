<?php

namespace Tests\Feature;

use App\Models\OwnerIdentityVerification;
use App\Models\Service;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A service's category (Massage, Facial… config/service_categories.php) is
// chosen on the owner's service form and kept: it is how add-ons, the catalog
// and the front desk group services. The form request only passes on fields it
// lists, so a category left off its rules is silently dropped.
class ServiceCategoryTest extends TestCase
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

    private function create(array $extra = [])
    {
        return $this->postJson('/api/business/service', array_merge([
            'name' => 'Classic Facial',
            'variants' => [['duration_minutes' => 60, 'price' => 900]],
        ], $extra));
    }

    public function test_a_new_service_keeps_its_category(): void
    {
        $uuid = $this->create(['category' => 'Facial'])->assertSuccessful()->json('data.uuid');

        $this->assertSame('Facial', Service::where('uuid', $uuid)->value('category'));
    }

    public function test_the_category_can_be_changed_and_cleared(): void
    {
        $uuid = $this->create(['category' => 'Facial'])->assertSuccessful()->json('data.uuid');

        $this->putJson("/api/business/service/{$uuid}", ['category' => 'Massage'])->assertSuccessful();
        $this->assertSame('Massage', Service::where('uuid', $uuid)->value('category'));

        // Saving other fields leaves it as it is.
        $this->putJson("/api/business/service/{$uuid}", ['description' => 'Deep cleanse'])->assertSuccessful();
        $this->assertSame('Massage', Service::where('uuid', $uuid)->value('category'));

        $this->putJson("/api/business/service/{$uuid}", ['category' => null])->assertSuccessful();
        $this->assertNull(Service::where('uuid', $uuid)->value('category'));
    }

    // Exactly what the owner's Add window and edit panel send
    // (servora-web useOwnerServices toApiPayload): the whole form each time.
    private function webForm(?string $category, array $variants = [['service_location' => 'In Spa', 'duration_minutes' => 60, 'price' => 900]]): array
    {
        return [
            'name' => 'Classic Facial', 'category' => $category, 'service_type' => 'time', 'facility_required' => false,
            'home_service_available' => false, 'variants' => $variants, 'parts' => [],
        ];
    }

    public function test_the_add_window_and_edit_panel_save_the_category(): void
    {
        $response = $this->postJson('/api/business/service', $this->webForm('Facial'))
            ->assertSuccessful()
            ->assertJsonPath('data.category', 'Facial');
        $uuid = $response->json('data.uuid');
        $variant = $response->json('data.variants.0.uuid');

        $edit = fn (?string $category) => $this->patchJson("/api/business/service/{$uuid}",
            $this->webForm($category, [['service_location' => 'In Spa', 'uuid' => $variant, 'duration_minutes' => 60, 'price' => 900]]));

        $edit('Body Treatment')->assertSuccessful()->assertJsonPath('data.category', 'Body Treatment');
        $this->assertSame('Body Treatment', Service::where('uuid', $uuid)->value('category'));

        // "Choose a category" again: no category.
        $edit(null)->assertSuccessful()->assertJsonPath('data.category', null);
        $this->assertNull(Service::where('uuid', $uuid)->value('category'));
    }

    public function test_a_category_not_on_the_list_is_refused(): void
    {
        $this->create(['category' => 'Pizza'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
        $this->assertSame(0, Service::whereNotNull('spa_business_id')->count());
    }
}
