<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Facility;
use App\Models\Package;
use App\Models\PackageServiceItem;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\User;
use App\Service\Business\ServiceExtensionService;
use App\Service\Business\ServiceService;
use App\Service\Business\SpaBranchService;
use App\Support\AppCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// "Open stay": a facility-based service (a sauna) the client stays in as long as they like, for
// one price. Its length is only a usual stay, for planning the facility; nothing treats it as a
// limit, and each booked line keeps its own copy of the setting.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class OpenStayTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private User $owner;
    private SpaBranch $branch;
    private Service $sauna;
    private ServiceVariant $saunaStay;
    private Service $massage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $business->id, 'verification_status' => 'Verified', 'operating_status' => 'Active',
            'listing_visible' => true, 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active']);

        $this->sauna = Service::factory()->create(['spa_business_id' => $business->id, 'name' => 'Sauna', 'service_type' => 'facility', 'facility_required' => true]);
        // The usual stay: two hours.
        $this->saunaStay = ServiceVariant::factory()->create(['service_id' => $this->sauna->id, 'duration_minutes' => 120, 'price' => 400]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->saunaStay->id]);
        Facility::factory()->create(['spa_branch_id' => $this->branch->id, 'name' => 'Sauna Room', 'max_clients' => 10])->services()->attach($this->sauna->id);

        $this->massage = Service::factory()->create(['spa_business_id' => $business->id, 'name' => 'Swedish Massage']);
        $this->makeBookable($this->branch);
    }

    private function setOpenStay(Service $service, bool $on): void
    {
        app(ServiceService::class)->updateService($this->owner, $service->uuid, ['open_stay' => $on]);
    }

    private function bookSauna(): Appointment
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'password' => Hash::make('secret-pass')]));
        $uuid = $this->postJson('/api/client/appointments', [
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => '2027-03-02',
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->saunaStay->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '0917'.random_int(1000000, 9999999), 'gender' => 'Female'],
        ])->assertSuccessful()->json('data.uuid');

        return Appointment::where('uuid', $uuid)->firstOrFail();
    }

    public function test_the_owner_makes_a_facility_service_an_open_stay(): void
    {
        $this->setOpenStay($this->sauna, true);

        $this->assertTrue($this->sauna->fresh()->isOpenStay());
        $this->assertTrue((new \App\Http\Resources\ServiceResource($this->sauna->fresh()))->resolve()['open_stay']);
    }

    public function test_only_a_facility_based_service_can_be_an_open_stay(): void
    {
        $this->setOpenStay($this->massage, true);
        $this->assertFalse($this->massage->fresh()->isOpenStay());

        // A sauna changed to time-based stops being one.
        $this->setOpenStay($this->sauna, true);
        app(ServiceService::class)->updateService($this->owner, $this->sauna->uuid, ['service_type' => 'time']);
        $this->assertFalse($this->sauna->fresh()->isOpenStay());
    }

    public function test_the_app_and_the_front_desk_are_told_it_is_an_open_stay(): void
    {
        $this->setOpenStay($this->sauna, true);
        $package = Package::factory()->create(['spa_business_id' => $this->sauna->spa_business_id, 'name' => 'Sauna Day']);
        PackageServiceItem::create(['package_id' => $package->id, 'service_variant_id' => $this->saunaStay->id, 'quantity' => 1, 'sort_order' => 1]);
        BranchPackage::factory()->create(['spa_branch_id' => $this->branch->id, 'package_id' => $package->id]);
        AppCache::bumpBusiness($this->sauna->spa_business_id);

        $data = app(SpaBranchService::class)->publicShow($this->branch->uuid)->getData(true)['data'];
        $this->assertTrue(collect($data['services'])->firstWhere('name', 'Sauna')['open_stay']);
        $this->assertTrue(collect($data['packages'])->firstWhere('name', 'Sauna Day')['open_stay']);
        // All of the package's minutes are the sauna's usual stay: the app shows "Open stay".
        $this->assertSame(120, collect($data['packages'])->firstWhere('name', 'Sauna Day')['open_stay_minutes']);

        $lookups = app(\App\Service\Business\FrontOfficeLookupService::class);
        $this->assertTrue(collect($lookups->services($this->owner)->resolve())->firstWhere('name', 'Sauna')['open_stay']);
        $deskPackage = collect($lookups->packages($this->owner)->resolve())->firstWhere('name', 'Sauna Day');
        $this->assertTrue($deskPackage['open_stay']);
        $this->assertSame(120, $deskPackage['open_stay_minutes']);
        $this->assertSame(['Sauna (open stay)'], $deskPackage['includes']->all());
    }

    public function test_a_booked_open_stay_keeps_its_own_copy_and_cannot_be_extended(): void
    {
        $this->setOpenStay($this->sauna, true);
        $line = $this->bookSauna()->services()->firstOrFail();

        $this->assertTrue($line->isOpenStay());
        // The owner changes their mind afterwards: the visit already booked doesn't.
        $this->setOpenStay($this->sauna, false);
        $this->assertTrue($line->fresh()->isOpenStay());
        $this->assertTrue((new \App\Http\Resources\AppointmentServiceResource($line->fresh()))->resolve()['open_stay']);

        $options = app(ServiceExtensionService::class)->optionsFor($line->fresh(), null)['data'];
        $this->assertFalse($options['can_extend']);
        $this->assertSame(ServiceExtensionService::OPEN_STAY, $options['reason']);
    }

    public function test_a_fixed_length_facility_service_is_not_an_open_stay(): void
    {
        $line = $this->bookSauna()->services()->firstOrFail();

        $this->assertFalse($line->isOpenStay());
        $this->assertSame(false, $line->open_stay);
    }
}
