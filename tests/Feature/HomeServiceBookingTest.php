<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Service\Client\BookingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// Home service (HomeServiceRules): a client books a visit at their own
// address. Offered only when the owner turns it on, only inside the branch's
// service area, only for services marked for it, with a flat fee on top.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=HomeServiceBookingTest
class HomeServiceBookingTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private const DATE = '2027-03-02'; // a Tuesday, well in the future

    // The branch's own pin, a spot about 1 km away, and one about 30 km away.
    private const BRANCH = [7.07, 125.61];
    private const NEAR = [7.079, 125.61];
    private const FAR = [7.34, 125.61];

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Service $service;
    private ServiceVariant $variant;
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
            'listing_visible' => true,
            'latitude' => self::BRANCH[0],
            'longitude' => self::BRANCH[1],
        ]);
        // Someone the owner has made available for home service.
        $this->staff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active', 'home_service_available' => true]);

        $this->service = Service::factory()->create(['spa_business_id' => $this->business->id, 'name' => 'Swedish Massage', 'home_service_available' => true]);
        $this->variant = ServiceVariant::factory()->create(['service_id' => $this->service->id, 'duration_minutes' => 60, 'price' => 700]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->variant->id]);
        $this->makeBookable($this->branch);
    }

    private function offerHomeService(int $fee = 300, int $radiusKm = 10, int $minMinutes = 0): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson('/api/business/settings/booking-defaults', [
            'home_service_enabled' => true,
            'home_service_fee' => $fee,
            'home_service_radius_km' => $radiusKm,
            'home_service_min_minutes' => $minMinutes,
        ])->assertOk();
        BookingPolicy::flush();
    }

    private function bookHome(array $pin = self::NEAR, array $overrides = [])
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'password' => Hash::make('secret-pass')]));

        return $this->postJson('/api/client/appointments', array_merge([
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '0917' . random_int(1000000, 9999999)],
            'service_location' => 'Home Service',
            'home_address' => '12 Acacia St, Davao City',
            'home_address_notes' => 'Blue gate',
            'home_latitude' => $pin[0],
            'home_longitude' => $pin[1],
        ], $overrides));
    }

    public function test_home_service_is_off_until_the_owner_turns_it_on(): void
    {
        $this->bookHome()->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_home_visit_inside_the_area_is_booked_with_the_fee_on_top(): void
    {
        $this->offerHomeService(fee: 300);

        $this->bookHome()
            ->assertSuccessful()
            ->assertJsonPath('data.is_home_service', true)
            ->assertJsonPath('data.home_address', '12 Acacia St, Davao City')
            ->assertJsonPath('data.home_service_fee', 300.0)
            ->assertJsonPath('data.total_amount', 1000.0);

        $appointment = Appointment::sole();
        $this->assertSame('Home Service', $appointment->service_location);
        $this->assertEqualsWithDelta(1.0, $appointment->home_distance_km, 0.2);
    }

    public function test_an_address_outside_the_area_is_refused(): void
    {
        $this->offerHomeService(radiusKm: 10);

        $this->bookHome(self::FAR)->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_home_visit_needs_a_pin(): void
    {
        $this->offerHomeService();

        $this->bookHome(self::NEAR, ['home_latitude' => null, 'home_longitude' => null])->assertStatus(422);
    }

    public function test_a_service_not_marked_for_home_service_is_refused(): void
    {
        $this->offerHomeService();
        $this->service->update(['home_service_available' => false]);

        $this->bookHome()->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_an_in_spa_booking_has_no_fee(): void
    {
        $this->offerHomeService(fee: 300);

        $this->bookHome(self::NEAR, ['service_location' => 'In Spa'])
            ->assertSuccessful()
            ->assertJsonPath('data.is_home_service', false)
            ->assertJsonPath('data.home_service_fee', 0.0)
            ->assertJsonPath('data.total_amount', 700.0);
    }

    // The one service in these tests takes 60 minutes.
    public function test_the_app_refuses_a_home_visit_shorter_than_the_minimum(): void
    {
        $this->offerHomeService(minMinutes: 90);

        $this->bookHome()->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_home_visit_exactly_at_the_minimum_is_booked(): void
    {
        $this->offerHomeService(minMinutes: 60);

        $this->bookHome()->assertSuccessful();
    }

    public function test_the_minimum_is_only_for_home_visits(): void
    {
        $this->offerHomeService(minMinutes: 90);

        $this->bookHome(self::NEAR, ['service_location' => 'In Spa'])->assertSuccessful();
    }

    public function test_the_front_desk_is_warned_not_stopped_by_the_minimum(): void
    {
        $this->offerHomeService(minMinutes: 90);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/business/appointment', [
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes'],
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'service_location' => 'Home Service',
            'home_address' => '12 Acacia St, Davao City',
            'home_latitude' => self::NEAR[0],
            'home_longitude' => self::NEAR[1],
        ])->assertSuccessful();

        $this->assertSame(1, Appointment::count());
    }

    // The owner's "Available for home service" tick on each staff member.
    public function test_a_branch_where_nobody_goes_out_does_not_offer_home_service(): void
    {
        $this->staff->update(['home_service_available' => false]);
        $this->offerHomeService();

        $this->getJson("/api/spas/{$this->branch->uuid}")
            ->assertOk()
            ->assertJsonPath('data.home_service.enabled', false)
            ->assertJsonPath('data.home_service.staff_available', false);
        $this->bookHome()->assertStatus(422)->assertJsonPath('message', "{$this->branch->branch_name} has no one available for home service right now.");
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_requested_therapist_who_does_not_go_out_is_refused(): void
    {
        $other = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active', 'home_service_available' => false]);
        $this->offerHomeService();

        $this->bookHome(self::NEAR, ['requested_therapist_uuid' => $other->uuid])
            ->assertStatus(422)
            ->assertJsonPath('message', "{$other->first_name} {$other->last_name} isn't available for home service. Please choose someone who is.");
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_branch_page_tells_the_app_what_is_offered(): void
    {
        $this->offerHomeService(fee: 250, radiusKm: 8, minMinutes: 60);

        $this->getJson("/api/spas/{$this->branch->uuid}")
            ->assertOk()
            ->assertJsonPath('data.home_service.enabled', true)
            ->assertJsonPath('data.home_service.fee', 250.0)
            ->assertJsonPath('data.home_service.radius_km', 8)
            ->assertJsonPath('data.home_service.min_minutes', 60)
            ->assertJsonPath('data.services.0.home_service_available', true);
    }
}
