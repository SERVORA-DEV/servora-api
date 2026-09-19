<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffQualification;
use App\Models\User;
use App\Service\Business\AppointmentService;
use App\Service\Business\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

// Covers therapist service eligibility (staff_services) end to end on the
// staff-facing side: the manager's read/write sub-resource, and the front
// desk's therapist picker that has to agree with it.
//
// The pairing is the point, same as TherapistAvailabilityTest: if
// therapistOptions() says a therapist is pickable but assignTherapist()
// rejects them, the front desk only finds out via a 422 after committing to
// the choice.
//
// The rule under test throughout is opt-in-if-configured
// (StaffServiceRepository::isQualified): zero rows for a staff member means
// qualified for EVERYTHING, not nothing. It only becomes a whitelist once at
// least one row exists — which is what makes rolling this out possible
// without configuring every therapist first.
//
// Needs MySQL. phpunit.xml points at sqlite :memory:, which cannot build this
// schema at all — several migrations use raw `ALTER TABLE ... MODIFY ... ENUM`,
// which is MySQL-only. Run against the existing test database instead:
//
//   DB_CONNECTION=mysql DB_DATABASE=servora_testing DB_URL= \
//     php artisan test --filter=TherapistQualificationTest
class TherapistQualificationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2027-03-02';

    private AppointmentService $appointmentService;
    private StaffService $staffService;
    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private Client $client;
    private Staff $therapist;
    private Service $massage;
    private Service $facial;
    private ServiceVariant $massageVariant;
    private ServiceVariant $facialVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appointmentService = app(AppointmentService::class);
        $this->staffService = app(StaffService::class);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
        $this->client = Client::factory()->create(['spa_business_id' => $this->business->id]);

        $this->therapist = Staff::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'role' => 'therapist',
            'status' => 'active',
        ]);

        [$this->massage, $this->massageVariant] = $this->service('Swedish Massage');
        [$this->facial, $this->facialVariant] = $this->service('Classic Facial');
    }

    /** @return array{0: Service, 1: ServiceVariant} */
    private function service(string $name): array
    {
        $service = Service::factory()->create([
            'spa_business_id' => $this->business->id,
            'name' => $name,
        ]);
        $variant = ServiceVariant::factory()->create([
            'service_id' => $service->id,
            'duration_minutes' => 60,
        ]);
        BranchService::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'service_variant_id' => $variant->id,
        ]);

        return [$service, $variant];
    }

    /** Puts the therapist physically on the floor, so attendance stops being the blocker. */
    private function checkIn(): void
    {
        Attendance::create([
            'staff_id' => $this->therapist->id,
            'attendance_date' => self::DATE,
            'check_in_at' => self::DATE . ' 09:00:00',
            'status' => 'Present',
        ]);
    }

    private function bookedService(ServiceVariant $variant): AppointmentServiceItem
    {
        $created = $this->appointmentService->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $created, 'createAppointment failed: ' . $this->msg($created));

        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();

        $added = $this->appointmentService->addService($this->owner, $appointment->uuid, [
            'service_variant_uuid' => $variant->uuid,
        ]);
        $this->assertNotInstanceOf(JsonResponse::class, $added, 'addService failed: ' . $this->msg($added));

        return AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
    }

    /** @return array{qualified: bool, pickable: bool, state: string} the therapist's own row */
    private function option(AppointmentServiceItem $item): array
    {
        $rows = $this->appointmentService
            ->therapistOptions($this->owner, $item->uuid)
            ->getData(true)['data'];

        $this->assertCount(1, $rows, 'expected exactly the one seeded therapist');
        $this->assertSame($this->therapist->uuid, $rows[0]['uuid']);

        return $rows[0];
    }

    private function msg($result): string
    {
        return $result instanceof JsonResponse ? (string) ($result->getData(true)['message'] ?? '') : '';
    }

    // ── The picker ───────────────────────────────────────────────────────

    // The whole rollout rests on this: a therapist nobody has configured yet
    // stays assignable to everything, so the feature can ship before a single
    // manager has opened the new card.
    public function test_a_therapist_with_no_configured_services_is_qualified_for_everything(): void
    {
        $this->checkIn();

        $option = $this->option($this->bookedService($this->massageVariant));

        $this->assertTrue($option['qualified']);
        $this->assertTrue($option['pickable']);
    }

    public function test_a_configured_therapist_is_qualified_for_their_own_service(): void
    {
        $this->checkIn();
        StaffQualification::create(['staff_id' => $this->therapist->id, 'service_id' => $this->massage->id]);

        $option = $this->option($this->bookedService($this->massageVariant));

        $this->assertTrue($option['qualified']);
        $this->assertTrue($option['pickable']);
    }

    // The moment one row exists the set becomes a whitelist — everything not
    // in it is off-limits, even though it was allowed a moment earlier.
    public function test_a_configured_therapist_is_not_qualified_for_another_service(): void
    {
        $this->checkIn();
        StaffQualification::create(['staff_id' => $this->therapist->id, 'service_id' => $this->massage->id]);

        $option = $this->option($this->bookedService($this->facialVariant));

        $this->assertFalse($option['qualified']);
        $this->assertFalse($option['pickable']);
    }

    // Qualification is orthogonal to presence: the row still reports the real
    // attendance state, so the UI can show "not checked in" and "not
    // qualified" as the separate facts they are.
    public function test_qualification_is_reported_independently_of_attendance(): void
    {
        StaffQualification::create(['staff_id' => $this->therapist->id, 'service_id' => $this->massage->id]);

        $option = $this->option($this->bookedService($this->massageVariant));

        $this->assertTrue($option['qualified']);
        $this->assertFalse($option['pickable'], 'still not pickable — never checked in');
        $this->assertSame('not_checked_in', $option['state']);
    }

    // The picker and the assign action have to agree. Being physically present
    // buys a therapist past the schedule check but never past this one — a
    // shift can be renegotiated at the counter, a skill can't.
    public function test_assigning_an_unqualified_therapist_is_rejected_even_when_checked_in(): void
    {
        $this->checkIn();
        StaffQualification::create(['staff_id' => $this->therapist->id, 'service_id' => $this->massage->id]);

        $item = $this->bookedService($this->facialVariant);
        $result = $this->appointmentService->assignTherapist($this->owner, $item->uuid, [
            'staff_uuid' => $this->therapist->uuid,
        ]);

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertSame('This therapist is not qualified to perform this service.', $this->msg($result));
    }

    // ── The manager sub-resource ─────────────────────────────────────────

    public function test_services_round_trip_through_update_and_read(): void
    {
        $this->staffService->updateServices($this->owner, $this->therapist->uuid, [
            $this->massage->uuid,
            $this->facial->uuid,
        ]);

        $names = collect($this->staffService->services($this->owner, $this->therapist->uuid)['data'])
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Classic Facial', 'Swedish Massage'], $names);
    }

    // Wholesale replace, not a merge — the form always submits the complete
    // intended set (StaffServiceRepository::sync deletes then recreates).
    public function test_updating_replaces_the_previous_set_rather_than_adding_to_it(): void
    {
        $this->staffService->updateServices($this->owner, $this->therapist->uuid, [$this->massage->uuid]);
        $this->staffService->updateServices($this->owner, $this->therapist->uuid, [$this->facial->uuid]);

        $names = collect($this->staffService->services($this->owner, $this->therapist->uuid)['data'])
            ->pluck('name')
            ->all();

        $this->assertSame(['Classic Facial'], $names);
    }

    // An empty array is a valid, meaningful payload: it clears the whitelist
    // and puts the therapist back to "can perform anything".
    public function test_an_empty_payload_clears_every_restriction(): void
    {
        $this->staffService->updateServices($this->owner, $this->therapist->uuid, [$this->massage->uuid]);
        $this->staffService->updateServices($this->owner, $this->therapist->uuid, []);

        $this->assertSame([], $this->staffService->services($this->owner, $this->therapist->uuid)['data']->all());

        $this->checkIn();
        $this->assertTrue($this->option($this->bookedService($this->facialVariant))['qualified']);
    }

    // StaffServiceRequest validates only that the uuid exists in `services` at
    // all, so the business scoping is the service layer's job — a uuid from
    // someone else's catalog is dropped, not saved and not 422'd.
    public function test_a_service_from_another_business_is_silently_dropped(): void
    {
        $otherOwner = User::factory()->create(['role' => 'business_owner']);
        $otherBusiness = SpaBusiness::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = Service::factory()->create(['spa_business_id' => $otherBusiness->id]);

        $this->staffService->updateServices($this->owner, $this->therapist->uuid, [
            $this->massage->uuid,
            $foreign->uuid,
        ]);

        $names = collect($this->staffService->services($this->owner, $this->therapist->uuid)['data'])
            ->pluck('name')
            ->all();

        $this->assertSame(['Swedish Massage'], $names);
    }
}
