<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\Facility;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\AppointmentService;
use App\Service\Business\ServiceExecutionService;
use App\Service\Business\TherapistQueueCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

// Voiding a service (VisitItemsService::voidService): one that has finished — the client stopped
// part-way, so it was completed early — comes off the bill and off the therapist's commission, with
// the reason kept. One still running is completed first. The money rules are ServiceVoidRules
// (tests/Unit/ServiceVoidRulesTest).
class VoidServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2027-03-02';

    private AppointmentService $appointments;
    private ServiceExecutionService $execution;
    private User $owner;
    private SpaBranch $branch;
    private Client $client;
    private Staff $alice;
    private ServiceVariant $massage;
    private ServiceVariant $facial;
    private Facility $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appointments = app(AppointmentService::class);
        $this->execution = app(ServiceExecutionService::class);

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $this->client = Client::factory()->create(['spa_business_id' => $business->id]);
        $this->alice = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'first_name' => 'Alice', 'role' => 'therapist', 'status' => 'active']);
        $this->room = Facility::factory()->create(['spa_branch_id' => $this->branch->id]);

        foreach ([['Swedish Massage', 60, 'massage'], ['Facial', 30, 'facial']] as [$name, $minutes, $key]) {
            $service = Service::factory()->create(['spa_business_id' => $business->id, 'name' => $name]);
            $this->{$key} = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => $minutes]);
            BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->{$key}->id]);
        }

        \App\Models\SpaBusinessSetting::updateOrCreate(['spa_business_id' => $business->id], ['booking_defaults' => ['max_advance_booking_days' => 0]]);
        \App\Service\Client\BookingPolicy::flush();

        $this->at('08:00');
        Attendance::create(['staff_id' => $this->alice->id, 'attendance_date' => self::DATE, 'check_in_at' => self::DATE.' 08:00:00', 'status' => 'Present']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse(self::DATE." {$time}:00"));
    }

    private function ok($result, string $what)
    {
        $this->assertNotInstanceOf(JsonResponse::class, $result, "{$what} failed: ".($result instanceof JsonResponse ? ($result->getData(true)['message'] ?? '') : ''));

        return $result;
    }

    /**
     * A visit at 10:00 with these services, each with Alice, the first started at 10:00. Each in a
     * facility of its own, so they are one after another, not one sitting completed together.
     */
    private function visit(ServiceVariant ...$variants): array
    {
        $this->at('08:30');
        $created = $this->ok($this->appointments->createAppointment($this->owner, [
            'spa_branch_uuid' => $this->branch->uuid,
            'client_uuid' => $this->client->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
        ]), 'createAppointment');
        $appointment = Appointment::where('uuid', $created->resource->uuid)->firstOrFail();
        $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        $lines = [];
        foreach ($variants as $i => $variant) {
            $this->ok($this->appointments->addService($this->owner, $appointment->uuid, ['service_variant_uuid' => $variant->uuid]), 'addService');
            $line = AppointmentServiceItem::where('appointment_id', $appointment->id)->latest('id')->firstOrFail();
            $room = $i === 0 ? $this->room : Facility::factory()->create(['spa_branch_id' => $this->branch->id]);
            $this->ok($this->appointments->assignTherapist($this->owner, $line->uuid, ['staff_uuid' => $this->alice->uuid, 'facility_uuid' => $room->uuid]), 'assignTherapist');
            $lines[] = $line;
        }
        $this->at('10:00');
        $this->ok($this->execution->startService($this->owner, $lines[0]->uuid), 'startService');

        return [$appointment, ...array_map(fn ($l) => $l->fresh(), $lines)];
    }

    private function row(AppointmentServiceItem $line): TherapistAssignment
    {
        return TherapistAssignment::where('appointment_service_id', $line->id)->where('staff_id', $this->alice->id)->firstOrFail();
    }

    public function test_a_running_service_is_completed_first(): void
    {
        [, $line] = $this->visit($this->massage);
        $result = $this->appointments->voidService($this->owner, $line->uuid, ['reason' => 'Client unwell']);

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString('Complete this service first', $result->getData(true)['message']);
        $this->assertSame('In Progress', $line->fresh()->status, 'nothing changed');
    }

    public function test_a_service_stopped_part_way_is_voided_with_its_reason(): void
    {
        [$appointment, $massage, $facial] = $this->visit($this->massage, $this->facial);
        // Stopped 20 minutes in: completed early, then voided.
        $this->at('10:20');
        $this->ok($this->execution->completeService($this->owner, $massage->uuid), 'complete early');
        $this->assertSame(AssignmentStatus::Completed->value, $this->row($massage)->assignment_status);
        $before = (float) $appointment->fresh()->total_amount;

        $this->ok($this->appointments->voidService($this->owner, $massage->uuid, ['reason' => 'Client unwell']), 'void');

        $massage->refresh();
        $this->assertSame('Cancelled', $massage->status);
        $this->assertStringStartsWith('Voided by ', (string) $massage->notes);
        $this->assertStringContainsString('Client unwell', (string) $massage->notes);
        $done = $this->row($massage);
        $this->assertSame(AssignmentStatus::Cancelled->value, $done->assignment_status, 'no commission for it');
        $this->assertSame(self::DATE.' 10:20:00', $done->completed_at->format('Y-m-d H:i:s'), 'when it really stopped stays on record');
        $this->assertEqualsWithDelta($before - (float) $massage->subtotal, (float) $appointment->fresh()->total_amount, 0.01, 'off the bill');
        $this->assertSame('In Progress', $facial->fresh()->status, 'the next service carries on (Alice went straight on to it)');
    }

    public function test_a_voided_service_gives_the_therapist_their_turn_back(): void
    {
        [, $massage] = $this->visit($this->massage);
        $this->at('10:20');
        $this->ok($this->execution->completeService($this->owner, $massage->uuid), 'complete early');
        $this->ok($this->appointments->voidService($this->owner, $massage->uuid, ['reason' => 'Added by mistake']), 'void');

        $alice = app(TherapistQueueCalculator::class)->forBranch($this->branch->id, self::DATE)->firstWhere('staff_id', $this->alice->id);
        $this->assertNull($alice['last_completed_at'], 'a voided service is not one they finished');
        $this->assertTrue(app(AppointmentAvailabilityService::class)->isStaffCurrentlyBusy($this->alice->id)['ok'], 'and she is free');
    }

    public function test_voiding_the_only_service_puts_the_client_back_to_waiting(): void
    {
        [$appointment, $massage] = $this->visit($this->massage);
        $this->at('10:20');
        $this->ok($this->execution->completeService($this->owner, $massage->uuid), 'complete early');
        $this->ok($this->appointments->voidService($this->owner, $massage->uuid, ['reason' => 'Client unwell']), 'void');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::CheckedIn->value, $appointment->status, 'nothing served any more: they can have another service, or the visit is cancelled');
        $this->assertEqualsWithDelta(0.0, (float) $appointment->total_amount, 0.01);
    }
}
