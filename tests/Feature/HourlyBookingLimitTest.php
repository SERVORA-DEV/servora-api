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
use App\Service\Client\BookingSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// "Bookings per hour": the spa takes at most N reservations in one clock
// hour, counted by the hour the booking STARTS in (2:35 PM is in the 2 PM
// hour). A hard stop for the app and for the front desk; walk-ins are not
// counted. Times are on the 5-minute marks.
//
// Postgres only (see TherapistAvailabilityTest for why):
//   php artisan test --filter=HourlyBookingLimitTest
class HourlyBookingLimitTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private const DATE = '2027-03-02';   // a Tuesday: no hours row, so open 09:00–21:00

    private User $owner;
    private SpaBranch $branch;
    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $business->id,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
            'listing_visible' => true,
            'latitude' => 7.07,
            'longitude' => 125.61,
        ]);

        // Plenty of therapists: the hour's limit, not "nobody left", is what is tested.
        Staff::factory()->count(4)->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active']);

        $service = Service::factory()->create(['spa_business_id' => $business->id]);
        $this->variant = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => 30]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->variant->id]);
        $this->makeBookable($this->branch);
    }

    private function limit(?int $perHour): void
    {
        $this->branch->update(['booking_overrides' => $perHour === null ? null : ['max_bookings_per_hour' => $perHour]]);
        BookingPolicy::flush();
    }

    // One client per booking: a client may only have one a day.
    private function appBooking(string $time)
    {
        $client = User::factory()->create(['role' => 'client', 'password' => Hash::make('secret-pass')]);
        Sanctum::actingAs($client);

        return $this->postJson('/api/client/appointments', [
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => $time,
            'services' => [['service_variant_uuid' => $this->variant->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '0917' . random_int(1000000, 9999999)],
        ]);
    }

    private function deskBooking(string $time, string $type = 'Reservation')
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/business/appointment', [
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => $time,
            'appointment_type' => $type,
            'client' => ['first_name' => 'Walk', 'last_name' => 'In', 'gender' => 'Female'],
        ]);
    }

    private function slotsAt(string $time): array
    {
        $slots = app(BookingSlotService::class)->slots($this->branch, self::DATE, 30)['slots'];

        return collect($slots)->firstWhere('time', $time);
    }

    public function test_a_time_in_the_same_hour_counts_toward_that_hour(): void
    {
        $this->limit(2);

        $this->appBooking('14:05')->assertSuccessful();
        $this->appBooking('14:35')->assertSuccessful();

        // 14:55 is still the 2 PM hour: refused, and the hour reads full all through.
        $this->appBooking('14:55')->assertStatus(422)->assertJsonPath('message', 'That hour is fully booked (2 of 2). Please pick another hour.');
        $this->assertSame('hour_full', $this->slotsAt('14:00')['reason']);
        $this->assertSame('hour_full', $this->slotsAt('14:35')['reason']);
        $this->assertFalse($this->slotsAt('14:55')['available']);

        // The next hour is untouched.
        $this->assertTrue($this->slotsAt('15:00')['available']);
        $this->appBooking('15:00')->assertSuccessful();
    }

    public function test_the_slot_list_says_how_full_each_hour_is(): void
    {
        $this->limit(2);
        $this->appBooking('14:05')->assertSuccessful();

        $hours = collect(app(BookingSlotService::class)->slots($this->branch, self::DATE, 30)['hours']);

        $this->assertSame(['hour' => '14:00', 'booked' => 1, 'limit' => 2, 'full' => false], $hours->firstWhere('hour', '14:00'));
        $this->assertTrue($this->slotsAt('14:55')['available']);
    }

    public function test_times_are_offered_every_five_minutes_and_others_are_refused(): void
    {
        $this->limit(null);

        $times = array_column(app(BookingSlotService::class)->slots($this->branch, self::DATE, 30)['slots'], 'time');
        $this->assertContains('14:35', $times);
        $this->assertNotContains('14:34', $times);

        $this->appBooking('14:34')->assertStatus(422);
        $this->appBooking('14:35')->assertSuccessful();
    }

    public function test_no_limit_changes_nothing(): void
    {
        $this->limit(0);

        foreach (['14:00', '14:15', '14:30', '14:45'] as $time) {
            $this->appBooking($time)->assertSuccessful();
        }
        $this->assertSame([], app(BookingSlotService::class)->slots($this->branch, self::DATE, 30)['hours']);
    }

    public function test_the_front_desk_is_stopped_by_a_full_hour_too(): void
    {
        $this->limit(1);
        $this->deskBooking('14:05')->assertSuccessful();

        $this->deskBooking('14:50')->assertStatus(422)->assertJsonPath('message', 'That hour is fully booked (1 of 1). Please pick another hour.');
        $this->deskBooking('15:00')->assertSuccessful();
    }

    public function test_the_desk_has_to_use_the_five_minute_marks(): void
    {
        $this->limit(null);

        $this->deskBooking('14:07')->assertStatus(422);
        $this->deskBooking('14:10')->assertSuccessful();
    }

    public function test_the_booking_board_closes_a_full_hour_at_five_minute_steps(): void
    {
        $this->limit(1);
        $this->deskBooking('14:05')->assertSuccessful();

        $board = $this->getJson('/api/business/frontoffice/booking-board?date=' . self::DATE . '&duration=30&step=5')
            ->assertOk()->json('data');

        $states = array_column($board['slots'], 'state', 'time');
        $this->assertSame(5, $board['step_minutes']);
        $this->assertSame('hour_full', $states['14:35']);
        $this->assertSame('free', $states['15:00']);
        $this->assertTrue(collect($board['hours'])->firstWhere('hour', '14:00')['full']);
    }

    public function test_moving_a_booking_inside_its_own_hour_is_fine_but_not_into_a_full_one(): void
    {
        $this->limit(1);
        $mine = $this->deskBooking('14:05')->assertSuccessful()->json('data.uuid');
        $this->deskBooking('15:00')->assertSuccessful();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/business/appointment/{$mine}/reschedule", ['appointment_date' => self::DATE, 'appointment_time' => '14:20'])
            ->assertSuccessful();
        $this->patchJson("/api/business/appointment/{$mine}/reschedule", ['appointment_date' => self::DATE, 'appointment_time' => '15:20'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That hour is fully booked (1 of 1). Please pick another hour.');
    }

    public function test_a_cancelled_booking_gives_its_place_back(): void
    {
        $this->limit(1);
        $uuid = $this->deskBooking('14:05')->assertSuccessful()->json('data.uuid');
        $this->deskBooking('14:30')->assertStatus(422);

        Appointment::where('uuid', $uuid)->update(['status' => Appointment::STATUS_CANCELLED]);

        $this->deskBooking('14:30')->assertSuccessful();
    }
}
