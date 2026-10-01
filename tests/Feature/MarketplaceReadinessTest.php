<?php

namespace Tests\Feature;

use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\ClientFavorite;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Service\Business\MarketplaceReadiness;
use App\Service\Client\BookingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A branch only shows in the client app (browse + favorites) and only takes
// bookings once it's ready: business verified, something to book, opening
// hours, a working therapist, online booking on. The spa page itself stays
// reachable for existing links, flagged accepting_bookings = false.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=MarketplaceReadinessTest
class MarketplaceReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const NEARBY = '/api/spas/nearby?lat=7.07&lng=125.61';
    private const DATE = '2027-03-07'; // a Sunday, the day with hours below

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Staff $therapist;
    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'listing_visible' => true,
            'latitude' => 7.07,
            'longitude' => 125.61,
        ]);

        BranchSchedule::create([
            'spa_branch_id' => $this->branch->id, 'day_of_week' => 'Sunday',
            'opening_time' => '09:00:00', 'closing_time' => '21:00:00', 'is_closed' => false,
        ]);
        $this->therapist = Staff::factory()->create([
            'spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active',
        ]);
        $this->variant = ServiceVariant::factory()->create([
            'service_id' => Service::factory()->create(['spa_business_id' => $this->business->id])->id,
            'duration_minutes' => 60,
        ]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->variant->id]);

        $this->setPolicy(['max_advance_booking_days' => 0]);
    }

    private function setPolicy(array $defaults): void
    {
        $this->business->settings()->updateOrCreate([], ['booking_defaults' => $defaults]);
        BookingPolicy::flush();
    }

    private function assertListed(bool $listed): void
    {
        BookingPolicy::flush();
        $this->getJson(self::NEARBY)->assertOk()->assertJsonCount($listed ? 1 : 0, 'data');
        $this->getJson("/api/spas/{$this->branch->uuid}")
            ->assertOk()
            ->assertJsonPath('data.accepting_bookings', $listed);
    }

    public function test_a_ready_branch_is_listed_and_bookable(): void
    {
        $this->assertListed(true);
        $this->assertTrue(MarketplaceReadiness::check($this->branch)['ready']);
    }

    public function test_no_services_hides_it(): void
    {
        BranchService::query()->update(['is_available' => false]);
        $this->assertListed(false);
    }

    public function test_no_opening_hours_hides_it(): void
    {
        BranchSchedule::query()->update(['is_closed' => true]);
        $this->assertListed(false);
    }

    public function test_only_front_desk_staff_hides_it(): void
    {
        $this->therapist->update(['role' => 'frontdesk']);
        $this->assertListed(false);
    }

    public function test_an_inactive_therapist_hides_it(): void
    {
        $this->therapist->update(['status' => 'inactive']);
        $this->assertListed(false);
    }

    public function test_a_therapist_who_never_works_hides_it(): void
    {
        StaffSchedule::create(['staff_id' => $this->therapist->id, 'day_of_week' => 'Sunday', 'is_day_off' => true]);
        $this->assertListed(false);

        StaffSchedule::create(['staff_id' => $this->therapist->id, 'day_of_week' => 'Monday', 'start_time' => '09:00:00', 'end_time' => '18:00:00']);
        $this->assertListed(true);
    }

    public function test_a_suspended_business_hides_it(): void
    {
        $this->business->update(['verification_status' => 'Suspended']);
        $this->assertListed(false);
    }

    public function test_online_booking_off_hides_it(): void
    {
        $this->setPolicy(['online_booking_enabled' => false, 'max_advance_booking_days' => 0]);
        $this->assertListed(false);
    }

    public function test_favorites_only_list_ready_branches(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        ClientFavorite::create(['user_id' => $client->id, 'spa_branch_id' => $this->branch->id]);
        Sanctum::actingAs($client);

        $this->getJson('/api/client/favorites')->assertOk()->assertJsonCount(1, 'data');

        $this->therapist->update(['status' => 'inactive']);
        $this->getJson('/api/client/favorites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_unready_branch_refuses_bookings_and_offers_no_slots(): void
    {
        $this->therapist->update(['status' => 'inactive']);

        $this->getJson("/api/spas/{$this->branch->uuid}/slots?date=".self::DATE.'&duration_minutes=60')
            ->assertOk()
            ->assertJsonPath('data.closed_reason', MarketplaceReadiness::NOT_BOOKABLE)
            ->assertJsonCount(0, 'data.slots');

        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->postJson('/api/client/appointments', [
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '09171234567'],
        ])->assertStatus(422)->assertJsonPath('message', MarketplaceReadiness::NOT_BOOKABLE);
    }

    public function test_the_owner_sees_what_is_missing(): void
    {
        $this->therapist->update(['status' => 'inactive']);
        Sanctum::actingAs($this->owner);

        $readiness = $this->getJson("/api/business/branch/{$this->branch->uuid}/marketplace")
            ->assertOk()
            ->json('data.readiness');

        $this->assertFalse($readiness['ready']);
        $missing = collect($readiness['items'])->where('done', false)->pluck('key')->all();
        $this->assertSame(['therapists'], $missing);
    }
}
