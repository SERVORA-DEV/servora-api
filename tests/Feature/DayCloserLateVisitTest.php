<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Service\Business\DayCloser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The end-of-day sweep (DayCloser) at a branch open past midnight or round the
// clock: a late visit is not closed at the 2 AM line while it is still expected
// to be going; once its own expected end and the grace have passed, it is.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class DayCloserLateVisitTest extends TestCase
{
    use RefreshDatabase;

    private function lateBooking(int $minutes): Appointment
    {
        $business = SpaBusiness::factory()->create();
        $branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $client = Client::factory()->create(['spa_business_id' => $business->id]);
        $variant = ServiceVariant::factory()->create([
            'service_id' => Service::factory()->create(['spa_business_id' => $business->id])->id,
            'duration_minutes' => $minutes,
        ]);

        // Booked for 11:30 PM on Nov 21; the client never came.
        $appointment = Appointment::create([
            'spa_branch_id' => $branch->id,
            'client_id' => $client->id,
            'appointment_number' => 'APT-'.uniqid(),
            'appointment_date' => '2026-11-21',
            'appointment_time' => '23:30',
            'appointment_type' => 'Reservation',
            'source' => 'Mobile',
            'status' => 'scheduled',
        ]);
        AppointmentServiceItem::create([
            'appointment_id' => $appointment->id, 'service_variant_id' => $variant->id,
            'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'status' => 'Pending', 'sort_order' => 1,
        ]);

        return $appointment;
    }

    public function test_a_long_late_visit_is_left_alone_until_its_own_end_has_passed(): void
    {
        // 11:30 PM + 3 hours = 2:30 AM; with the 2-hour grace, closed from 4:30 AM.
        $appointment = $this->lateBooking(180);

        $this->travelTo(Carbon::parse('2026-11-22 02:10:00'));
        app(DayCloser::class)->sweep();
        $this->assertSame('scheduled', $appointment->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-22 04:40:00'));
        app(DayCloser::class)->sweep();
        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_a_short_late_visit_closes_at_the_usual_time(): void
    {
        // 11:30 PM + 30 minutes = midnight; with the grace, closed from 2 AM as before.
        $appointment = $this->lateBooking(30);

        $this->travelTo(Carbon::parse('2026-11-22 02:05:00'));
        app(DayCloser::class)->sweep();

        $this->assertSame('no_show', $appointment->fresh()->status);
    }
}
