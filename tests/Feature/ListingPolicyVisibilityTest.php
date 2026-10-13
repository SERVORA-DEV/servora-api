<?php

namespace Tests\Feature;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AppCache;
use App\Support\ListingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// The administrator's marketplace listing policy (Settings → Marketplace
// Policy): a branch missing an item marked Required is not shown in the
// client app; an Optional item never hides it.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=ListingPolicyVisibilityTest
class ListingPolicyVisibilityTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private const NEARBY = '/api/spas/nearby?lat=7.0731&lng=125.6128';

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create([
            'owner_id' => $this->owner->id,
            'verification_status' => 'Verified',
            'business_description' => 'A quiet day spa.',
        ]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'latitude' => 7.0731,
            'longitude' => 125.6128,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
            'listing_visible' => true,
            'cover_photo' => 'branches/b/registration',
            'highlights' => ['Private Rooms', 'Parking Available', 'Walk-ins Welcome'],
        ]);
        // Hours, a therapist and a service; this also makes every listing
        // item optional, so each test sets the policy it is about.
        $this->makeBookable($this->branch, withService: true);
        $this->policy(ListingPolicy::DEFAULTS);
    }

    private function policy(array $policy): void
    {
        SystemSetting::current()->update(['listing_policy' => ListingPolicy::normalize($policy)]);
        AppCache::bump('marketplace');
    }

    private function listed(): bool
    {
        return count($this->getJson(self::NEARBY)->assertOk()->json('data')) === 1;
    }

    public function test_a_branch_with_every_required_item_is_listed(): void
    {
        $this->assertTrue($this->listed());
    }

    public function test_a_missing_required_item_hides_the_branch(): void
    {
        $this->business->update(['business_description' => '   ']);
        AppCache::bump('marketplace');

        $this->assertFalse($this->listed());
    }

    public function test_too_few_highlights_hides_the_branch_while_they_are_required(): void
    {
        $this->branch->update(['highlights' => ['Private Rooms']]);

        $this->assertFalse($this->listed());

        $this->policy(['highlights' => 'optional']);

        $this->assertTrue($this->listed());
    }

    public function test_an_optional_item_never_hides_the_branch(): void
    {
        // No promo banner, and the starting policy leaves it optional.
        $this->assertNull($this->branch->promo_text);
        $this->assertTrue($this->listed());

        $this->policy(['promo' => 'required']);

        $this->assertFalse($this->listed());
    }

    public function test_a_gallery_photo_counts_as_the_cover(): void
    {
        $this->branch->update(['cover_photo' => null]);
        $this->assertFalse($this->listed());

        $this->branch->photos()->create(['path' => 'gallery/p1', 'sort_order' => 1, 'is_cover' => true]);

        $this->assertTrue($this->listed());
    }

    public function test_the_owner_sees_which_items_are_required_and_what_is_missing(): void
    {
        $this->branch->update(['highlights' => []]);
        Sanctum::actingAs($this->owner);

        $readiness = $this->getJson("/api/business/branch/{$this->branch->uuid}/marketplace")->assertOk()->json('data.readiness');

        $this->assertFalse($readiness['ready']);
        $polish = collect($readiness['polish'])->keyBy('key');
        $this->assertTrue($polish['highlights']['required']);
        $this->assertFalse($polish['highlights']['done']);
        $this->assertFalse($polish['promo']['required']);
        // The two no branch can be booked without are always required.
        foreach (['services', 'staff'] as $fixed) {
            $this->assertTrue($polish[$fixed]['required']);
            $this->assertTrue($polish[$fixed]['fixed']);
            $this->assertTrue($polish[$fixed]['done']);
        }
        $this->assertFalse($polish['highlights']['fixed']);
        // Required items join the checklist; optional ones don't.
        $keys = array_column($readiness['items'], 'key');
        $this->assertContains('polish_highlights', $keys);
        $this->assertNotContains('polish_promo', $keys);
    }

    public function test_a_hidden_branch_cannot_be_booked(): void
    {
        $this->policy(['promo' => 'required']);

        $this->getJson("/api/spas/{$this->branch->uuid}")
            ->assertOk()
            ->assertJsonPath('data.accepting_bookings', false);
    }
}
