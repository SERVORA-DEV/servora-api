<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
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
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Service\Business\SpaBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// A facility-based service (a sauna) is done by nobody: the client isn't asked for a
// therapist, a free therapist isn't needed for the time, and the booking doesn't hold one.
// It is put in a facility set up for it, and a time with no such facility free isn't offered.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class FacilityBookingTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private const DATE = '2027-03-02';

    private User $owner;
    private SpaBranch $branch;
    private Staff $ana;
    private ServiceVariant $massage;
    private ServiceVariant $sauna;
    private Facility $massageRoom;
    private Facility $saunaRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $business->id, 'verification_status' => 'Verified', 'operating_status' => 'Active',
            'listing_visible' => true, 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        // The one therapist.
        $this->ana = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active', 'first_name' => 'Ana', 'last_name' => 'Cruz']);

        [$massage, $this->massage] = $this->offer($business, 'Swedish Massage', 'time');
        [$sauna, $this->sauna] = $this->offer($business, 'Sauna', 'facility');

        $this->massageRoom = Facility::factory()->create(['spa_branch_id' => $this->branch->id, 'name' => 'Massage Room']);
        $this->massageRoom->services()->attach($massage->id);
        $this->saunaRoom = Facility::factory()->create(['spa_branch_id' => $this->branch->id, 'name' => 'Sauna Room']);
        $this->saunaRoom->services()->attach($sauna->id);

        $this->makeBookable($this->branch);
    }

    /** @return array{0: Service, 1: ServiceVariant} */
    private function offer(SpaBusiness $business, string $name, string $kind): array
    {
        $service = Service::factory()->create(['spa_business_id' => $business->id, 'name' => $name, 'service_type' => $kind, 'facility_required' => $kind === 'facility']);
        $variant = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => 60]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $variant->id]);

        return [$service, $variant];
    }

    private function book(array $variants, array $extra = [], string $time = '10:00')
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'password' => Hash::make('secret-pass')]));

        return $this->postJson('/api/client/appointments', array_merge([
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => $time,
            'services' => array_map(fn (ServiceVariant $v) => ['service_variant_uuid' => $v->uuid], $variants),
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '0917'.random_int(1000000, 9999999), 'gender' => 'Female'],
        ], $extra));
    }

    /** time => [available, reason] for a booking of these options. */
    private function slots(array $variants): array
    {
        $query = ['date' => self::DATE, 'duration_minutes' => 60];
        foreach ($variants as $i => $v) {
            $query["service_variant_uuids[$i]"] = $v->uuid;
        }
        $rows = $this->getJson('/api/spas/'.$this->branch->uuid.'/slots?'.http_build_query($query))->assertOk()->json('data.slots');

        return collect($rows)->mapWithKeys(fn ($s) => [$s['time'] => [$s['available'], $s['reason']]])->all();
    }

    /** [staff id, facility id] of each live assignment on the booking. */
    private function placed(string $uuid): array
    {
        $lineIds = Appointment::where('uuid', $uuid)->firstOrFail()->services()->pluck('id');

        return TherapistAssignment::whereIn('appointment_service_id', $lineIds)
            ->where('assignment_status', AssignmentStatus::Assigned->value)
            ->get()->map(fn ($a) => [$a->staff_id, $a->facility_id])->all();
    }

    public function test_a_sauna_is_offered_and_booked_while_the_only_therapist_is_busy(): void
    {
        $this->book([$this->massage])->assertSuccessful();

        $this->assertSame([false, 'full'], $this->slots([$this->massage])['10:00']);
        $this->assertSame([true, null], $this->slots([$this->sauna])['10:00']);

        $uuid = $this->book([$this->sauna])->assertSuccessful()->json('data.uuid');
        // Put in the sauna room, with nobody on it.
        $this->assertSame([[null, $this->saunaRoom->id]], $this->placed($uuid));
    }

    public function test_a_sauna_booking_does_not_hold_the_therapist(): void
    {
        $this->book([$this->sauna])->assertSuccessful();

        $this->assertSame([true, null], $this->slots([$this->massage])['10:00']);
        $uuid = $this->book([$this->massage])->assertSuccessful()->json('data.uuid');
        $this->assertSame([[$this->ana->id, $this->massageRoom->id]], $this->placed($uuid));
    }

    public function test_a_full_sauna_room_closes_that_time(): void
    {
        $this->book([$this->sauna])->assertSuccessful();

        $slots = $this->slots([$this->sauna]);
        $this->assertSame([false, 'room_full'], $slots['10:00']);
        $this->assertSame([true, null], $slots['11:00']);

        $this->book([$this->sauna])->assertStatus(422)
            ->assertJsonPath('message', 'Every facility for that service is taken at that time. Please pick another time.');
        $this->book([$this->sauna], [], '11:00')->assertSuccessful();
    }

    public function test_a_room_for_several_takes_that_many(): void
    {
        $this->saunaRoom->update(['max_clients' => 2]);
        $this->book([$this->sauna])->assertSuccessful();

        $this->assertSame([true, null], $this->slots([$this->sauna])['10:00']);
        $this->book([$this->sauna])->assertSuccessful();
        $this->assertSame([false, 'room_full'], $this->slots([$this->sauna])['10:00']);
    }

    public function test_a_sauna_with_a_massage_still_needs_the_therapist(): void
    {
        $this->book([$this->massage])->assertSuccessful();

        $this->assertSame([false, 'full'], $this->slots([$this->sauna, $this->massage])['10:00']);
        $this->book([$this->sauna, $this->massage])->assertStatus(422);
    }

    public function test_a_therapist_sent_with_a_sauna_is_ignored(): void
    {
        // Ana is busy then; for a sauna that doesn't matter, and she isn't put on it.
        $this->book([$this->massage])->assertSuccessful();

        $uuid = $this->book([$this->sauna], ['requested_therapist_uuid' => $this->ana->uuid])->assertSuccessful()->json('data.uuid');
        $this->assertSame([[null, $this->saunaRoom->id]], $this->placed($uuid));
    }

    public function test_my_bookings_say_a_sauna_needs_no_therapist(): void
    {
        $this->book([$this->sauna, $this->massage], [], '12:00')->assertSuccessful();

        $lines = collect($this->getJson('/api/client/appointments')->assertOk()->json('data.0.services'));
        $this->assertSame(['Sauna' => false, 'Swedish Massage' => true], $lines->pluck('needs_therapist', 'name')->sortKeys()->all());
    }

    private function saunaPackage(): Package
    {
        $package = Package::factory()->create(['spa_business_id' => $this->branch->spa_business_id, 'name' => 'Sauna Day']);
        PackageServiceItem::create(['package_id' => $package->id, 'service_variant_id' => $this->sauna->id, 'quantity' => 1, 'sort_order' => 1]);
        BranchPackage::factory()->create(['spa_branch_id' => $this->branch->id, 'package_id' => $package->id]);

        return $package;
    }

    public function test_a_sauna_only_package_is_offered_while_the_therapist_is_busy_and_needs_its_room(): void
    {
        $package = $this->saunaPackage();
        $this->book([$this->massage])->assertSuccessful();

        $at = fn () => collect($this->getJson('/api/spas/'.$this->branch->uuid.'/slots?'.http_build_query(['date' => self::DATE, 'duration_minutes' => 60, 'package_uuid' => $package->uuid]))
            ->assertOk()->json('data.slots'))->firstWhere('time', '10:00');
        $this->assertTrue($at()['available']);

        $this->book([$this->sauna])->assertSuccessful();
        $this->assertSame('room_full', $at()['reason']);
    }

    /** Service names the app is offered, and package names. */
    private function menu(): array
    {
        // As a save does (FacilityService bumps the business after changing what a facility takes).
        \App\Support\AppCache::bumpBusiness($this->branch->spa_business_id);
        $data =app(SpaBranchService::class)->publicShow($this->branch->uuid)->getData(true)['data'];

        return [collect($data['services'])->pluck('name')->sort()->values()->all(), collect($data['packages'])->pluck('name')->all()];
    }

    public function test_a_sauna_no_facility_has_turned_on_is_not_offered_or_bookable(): void
    {
        $this->saunaPackage();
        $this->assertSame([['Sauna', 'Swedish Massage'], ['Sauna Day']], $this->menu());

        // The sauna room no longer takes the sauna.
        $this->saunaRoom->services()->detach();

        $this->assertSame([['Swedish Massage'], []], $this->menu());
        $this->book([$this->sauna])->assertStatus(422)
            ->assertJsonPath('message', "Sauna can't be booked at this branch right now: no facility here is set up for it.");
        $this->assertSame(0, Appointment::count());
        // Nor is a time offered for it.
        $this->assertSame([false, 'room_full'], $this->slots([$this->sauna])['10:00']);
    }

    public function test_turning_the_sauna_off_on_the_facility_page_takes_it_off_the_app_at_once(): void
    {
        $show = fn () => collect(app(SpaBranchService::class)->publicShow($this->branch->uuid)->getData(true)['data']['services'])->pluck('name')->all();
        $this->assertContains('Sauna', $show());

        app(\App\Service\Business\FacilityService::class)->updateFacility($this->owner, $this->saunaRoom->uuid, ['service_ids' => []]);

        $this->assertNotContains('Sauna', $show());
    }

    public function test_the_front_desk_is_not_offered_a_sauna_no_facility_has_turned_on(): void
    {
        $this->saunaPackage();
        $lookups = app(\App\Service\Business\FrontOfficeLookupService::class);
        $names = fn () => [
            collect($lookups->services($this->owner)->resolve())->pluck('name')->unique()->sort()->values()->all(),
            collect($lookups->packages($this->owner)->resolve())->pluck('name')->all(),
        ];
        $this->assertSame([['Sauna', 'Swedish Massage'], ['Sauna Day']], $names());

        $this->saunaRoom->services()->detach();

        $this->assertSame([['Swedish Massage'], []], $names());
    }

    public function test_the_front_desk_cannot_add_a_sauna_no_facility_has_turned_on(): void
    {
        $uuid = $this->book([$this->massage])->assertSuccessful()->json('data.uuid');
        $this->saunaRoom->services()->detach();

        try {
            app(\App\Service\Business\AppointmentService::class)->addService($this->owner, $uuid, ['service_variant_uuid' => $this->sauna->uuid]);
            $this->fail('The sauna was added.');
        } catch (\App\Exceptions\ActionRefused $e) {
            $this->assertSame("Sauna can't be booked at this branch right now: no facility here is set up for it.", $e->getMessage());
        }
        $this->assertSame(1, Appointment::where('uuid', $uuid)->first()->services()->count());

        // Nor a package with it.
        $package = $this->saunaPackage();
        $this->expectException(\App\Exceptions\ActionRefused::class);
        app(\App\Service\Business\AppointmentService::class)->addPackage($this->owner, $uuid, ['package_uuid' => $package->uuid]);
    }

    public function test_the_front_desk_can_still_add_a_sauna_a_facility_has_turned_on(): void
    {
        $uuid = $this->book([$this->massage])->assertSuccessful()->json('data.uuid');

        app(\App\Service\Business\AppointmentService::class)->addService($this->owner, $uuid, ['service_variant_uuid' => $this->sauna->uuid]);

        $this->assertSame(2, Appointment::where('uuid', $uuid)->first()->services()->count());
    }

    public function test_a_sauna_room_that_is_switched_off_does_not_count(): void
    {
        $this->saunaRoom->update(['is_available' => false]);

        $this->assertSame([['Swedish Massage'], []], $this->menu());
        $this->book([$this->sauna])->assertStatus(422);
    }

    public function test_a_sauna_room_under_maintenance_keeps_the_sauna_listed_but_no_time_is_free(): void
    {
        $this->saunaRoom->update(['status' => 'Maintenance']);

        $this->assertContains('Sauna', $this->menu()[0]);
        $this->assertSame([false, 'room_full'], $this->slots([$this->sauna])['10:00']);
    }

    public function test_the_app_is_told_which_items_need_a_therapist(): void
    {
        $this->saunaPackage();

        $data = app(SpaBranchService::class)->publicShow($this->branch->uuid)->getData(true)['data'];

        $needs = collect($data['services'])->pluck('needs_therapist', 'name')->all();
        $this->assertSame(['Sauna' => false, 'Swedish Massage' => true], $needs);
        $this->assertFalse(collect($data['packages'])->firstWhere('name', 'Sauna Day')['needs_therapist']);
    }
}
