<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\BranchService;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Notification;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\User;
use App\Service\Business\BillingService;
use App\Service\Business\CustomerProgramService;
use App\Service\Business\ProgramRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesBranchesBookable;
use Tests\TestCase;

// A member books in the app with the discount their membership includes, the same way as
// with a voucher: held on the booking, put on the bill when it is made. A booking carries
// one program, a voucher or a member discount, never both. A voucher or discount the owner
// adds to a membership reaches today's members too.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class MemberDiscountBookingTest extends TestCase
{
    use MakesBranchesBookable, RefreshDatabase;

    private const DATE = '2027-03-02';

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;
    private ServiceVariant $massage;
    private User $user;
    private Client $client;
    private CustomerProgram $membership;
    private CustomerProgram $memberDiscount;
    private CustomerProgram $voucherProgram;
    private ClientMembership $held;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id, 'verification_status' => 'Verified', 'operating_status' => 'Active',
            'listing_visible' => true, 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active']);
        $service = Service::factory()->create(['spa_business_id' => $this->business->id, 'name' => 'Swedish Massage']);
        $this->massage = ServiceVariant::factory()->create(['service_id' => $service->id, 'duration_minutes' => 60, 'price' => 1000]);
        BranchService::factory()->create(['spa_branch_id' => $this->branch->id, 'service_variant_id' => $this->massage->id, 'custom_price' => 1000]);
        $this->makeBookable($this->branch);

        // Customer programs on: the owner's switch, and a plan with rewards.
        Subscription::where('spa_business_id', $this->business->id)->first()->plan->update(['reward_access' => true]);
        SpaBusinessSetting::where('spa_business_id', $this->business->id)->first()->update(['programs' => ['enabled' => true]]);
        Cache::flush();

        $this->memberDiscount = $this->program('discount', 'Members Discount', ['kind' => '%', 'percent' => '10', 'appliesTo' => 'All Services'], 'members');
        $this->voucherProgram = $this->program('voucher', 'Welcome New Client', ['dealType' => '₱ off', 'value' => '50', 'validDays' => '3 months']);
        $this->membership = $this->program('membership', 'Sauna Membership', ['price' => '0', 'billing' => 'Monthly']);
        $this->membership->items()->sync([$this->memberDiscount->id]);

        $this->user = User::factory()->create(['role' => 'client']);
        $this->client = Client::factory()->create(['spa_business_id' => $this->business->id, 'user_id' => $this->user->id, 'first_name' => 'Mia', 'last_name' => 'Reyes']);
        $this->held = ClientMembership::create([
            'client_id' => $this->client->id, 'customer_program_id' => $this->membership->id, 'spa_branch_id' => $this->branch->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active', 'price' => 0,
        ]);
    }

    private function program(string $type, string $name, array $values, string $audience = 'all'): CustomerProgram
    {
        $program = CustomerProgram::create([
            'spa_business_id' => $this->business->id, 'type' => $type, 'name' => $name, 'is_active' => true,
            'audience' => $audience, 'values' => $values, 'condition_kind' => $type === 'voucher' ? 'membership_only' : null,
        ]);
        $program->branches()->sync([$this->branch->id]);

        return $program;
    }

    private function book(array $extra = [])
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/client/appointments', array_merge([
            'spa_branch_uuid' => $this->branch->uuid,
            'appointment_date' => self::DATE,
            'appointment_time' => '10:00',
            'services' => [['service_variant_uuid' => $this->massage->uuid]],
            'client' => ['first_name' => 'Mia', 'last_name' => 'Reyes', 'phone_number' => '09171234567', 'gender' => 'Female'],
        ], $extra));
    }

    private function bill(Appointment $appointment): Billing
    {
        $billing = Billing::create([
            'appointment_id' => $appointment->id, 'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id,
            'billing_type' => 'Appointment', 'billing_number' => 'BIL-'.uniqid(), 'subtotal' => 1000, 'amount' => 1000,
            'status' => 'Pending', 'issued_at' => now(),
        ]);
        app(BillingService::class)->applyReservedVoucher($billing);

        return $billing->fresh();
    }

    private function rewardsDiscount(): array
    {
        Sanctum::actingAs($this->user);
        $spa = collect($this->getJson('/api/client/rewards')->assertOk()->json('data'))->firstWhere('business.uuid', $this->business->uuid);

        return collect($spa['discounts'])->firstWhere('name', 'Members Discount');
    }

    public function test_a_member_is_offered_their_discount_to_book_with(): void
    {
        $row = $this->rewardsDiscount();

        $this->assertTrue($row['bookable']);
        $this->assertSame('percent', $row['kind']);
        $this->assertEquals(10, $row['value']);
    }

    public function test_a_member_books_with_their_discount_and_the_bill_takes_it_off(): void
    {
        $uuid = $this->book(['discount_program_uuid' => $this->memberDiscount->uuid])->assertSuccessful()->json('data.uuid');

        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($this->memberDiscount->id, $appointment->discount_program_id);
        $this->assertSame('Members Discount', $this->getJson('/api/client/appointments')->json('data.0.member_discount.name'));

        $billing = $this->bill($appointment);
        $this->assertEquals(100, $billing->discount_amount);
        $this->assertEquals(900, $billing->amount);
        $this->assertSame($this->memberDiscount->id, $billing->discount_program_id);
        $this->assertNull($billing->client_voucher_id);
    }

    public function test_a_voucher_and_the_member_discount_together_are_refused(): void
    {
        $voucher = app(ProgramRewardService::class)->issueVoucher($this->client, $this->voucherProgram, 'membership', membership: $this->held);

        $this->book(['discount_program_uuid' => $this->memberDiscount->uuid, 'voucher_uuid' => $voucher->uuid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use either a voucher or your member discount on a booking, not both.');
        $this->assertSame(0, Appointment::count());
    }

    public function test_reserved_discount_is_quoted_before_check_in_on_mobile_and_web(): void
    {
        $uuid = $this->book(['discount_program_uuid' => $this->memberDiscount->uuid])->assertSuccessful()->json('data.uuid');
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $appointment->update(['subtotal' => 2700, 'total_amount' => 2700]);
        $this->getJson('/api/client/appointments/' . $uuid)->assertOk()
            ->assertJsonPath('data.total_amount', 2430)
            ->assertJsonPath('data.discount_amount', 270)
            ->assertJsonPath('data.discount_reason', 'Discount: Members Discount');
        $web = (new \App\Http\Resources\AppointmentResource($appointment->fresh()))->resolve();
        $this->assertEquals(2430, $web['total_amount']);
        $this->assertNull($appointment->fresh()->billing);
        $this->assertNull($appointment->fresh()->check_in_at);
    }

    public function test_a_reserved_free_service_shows_zero_without_consuming_the_voucher(): void
    {
        $this->voucherProgram->update(['name' => 'Free Massage', 'values' => ['dealType' => 'Free service', 'usableOn' => 'Swedish Massage']]);
        $voucher = app(ProgramRewardService::class)->issueVoucher($this->client, $this->voucherProgram, 'membership', membership: $this->held);
        $uuid = $this->book(['voucher_uuid' => $voucher->uuid])->assertSuccessful()->json('data.uuid');
        $this->getJson('/api/client/appointments/' . $uuid)->assertOk()
            ->assertJsonPath('data.total_amount', 0)
            ->assertJsonPath('data.discount_amount', 1000)
            ->assertJsonPath('data.discount_reason', 'Voucher: Free Massage');
        $this->assertSame('available', $voucher->fresh()->status);
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->assertNull($appointment->billing);
        $web = (new \App\Http\Resources\AppointmentResource($appointment))->resolve();
        $this->assertEquals(0, $web['total_amount']);
    }

    public function test_mobile_uses_the_recorded_bill_discount_after_checkout(): void
    {
        $uuid = $this->book(['discount_program_uuid' => $this->memberDiscount->uuid])->assertSuccessful()->json('data.uuid');
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $bill = $this->bill($appointment);
        $bill->update(['amount' => 850, 'discount_amount' => 150, 'discount_reason' => 'Approved discount']);
        $this->getJson('/api/client/appointments/' . $uuid)->assertOk()
            ->assertJsonPath('data.total_amount', 850)
            ->assertJsonPath('data.discount_amount', 150)
            ->assertJsonPath('data.discount_reason', 'Approved discount');
    }

    public function test_a_reserved_money_off_voucher_is_shown_before_billing(): void
    {
        $voucher = app(ProgramRewardService::class)->issueVoucher($this->client, $this->voucherProgram, 'membership', membership: $this->held);
        $uuid = $this->book(['voucher_uuid' => $voucher->uuid])->assertSuccessful()->json('data.uuid');
        $this->getJson('/api/client/appointments/' . $uuid)->assertOk()
            ->assertJsonPath('data.subtotal', 1000)
            ->assertJsonPath('data.discount_amount', 50)
            ->assertJsonPath('data.total_amount', 950)
            ->assertJsonPath('data.discount_reason', 'Voucher: Welcome New Client');
        $this->assertSame('available', $voucher->fresh()->status);
    }

    public function test_someone_who_is_not_a_member_cannot_book_with_it(): void
    {
        $this->held->update(['status' => 'cancelled']);

        $this->assertFalse($this->rewardsDiscount()['bookable']);
        $this->book(['discount_program_uuid' => $this->memberDiscount->uuid])->assertStatus(422);
    }

    public function test_a_membership_that_ended_before_the_bill_leaves_the_full_price_and_tells_the_client(): void
    {
        $uuid = $this->book(['discount_program_uuid' => $this->memberDiscount->uuid])->assertSuccessful()->json('data.uuid');
        $this->held->update(['status' => 'expired', 'ends_at' => now()->subMinute()]);

        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $billing = $this->bill($appointment);

        $this->assertEquals(0, $billing->discount_amount);
        $this->assertEquals(1000, $billing->amount);
        $this->assertNull($appointment->fresh()->discount_program_id);
        $this->assertTrue(Notification::where('user_id', $this->user->id)->where('title', "Your member discount wasn't applied")->exists());
    }

    public function test_a_voucher_added_to_the_membership_reaches_todays_members(): void
    {
        $this->assertSame(0, ClientVoucher::where('client_id', $this->client->id)->count());

        app(CustomerProgramService::class)->update($this->owner, $this->membership->uuid, [
            'includes' => ['voucher_uuids' => [$this->voucherProgram->uuid], 'discount_uuids' => [$this->memberDiscount->uuid]],
        ]);

        $voucher = ClientVoucher::where('client_id', $this->client->id)->sole();
        $this->assertSame($this->voucherProgram->id, $voucher->customer_program_id);
        $this->assertSame($this->held->id, $voucher->client_membership_id);
        $this->assertSame($this->held->ends_at->toIso8601String(), $voucher->expires_at->toIso8601String());
        $this->assertContains($this->voucherProgram->id, $this->held->fresh()->perkIds());
        $this->assertTrue(Notification::where('user_id', $this->user->id)->where('title', 'New in your membership')->exists());

        // The daily run gives nothing twice.
        $this->assertSame(0, app(ProgramRewardService::class)->runDaily()['member_perks']);
        $this->assertSame(1, ClientVoucher::where('client_id', $this->client->id)->count());
    }

    public function test_the_daily_run_gives_members_a_voucher_added_before_it_ran(): void
    {
        // Added straight to the bundle (as before this change), so nobody got it.
        $this->membership->items()->sync([$this->memberDiscount->id, $this->voucherProgram->id]);

        $this->assertSame(1, app(ProgramRewardService::class)->runDaily()['member_perks']);
        $this->assertSame(1, ClientVoucher::where('client_id', $this->client->id)->where('customer_program_id', $this->voucherProgram->id)->count());
    }

    public function test_a_discount_added_to_the_membership_can_be_booked_with_by_todays_members(): void
    {
        $extra = $this->program('discount', 'Sauna Members 20', ['kind' => '%', 'percent' => '20'], 'members');

        app(CustomerProgramService::class)->update($this->owner, $this->membership->uuid, [
            'includes' => ['voucher_uuids' => [], 'discount_uuids' => [$this->memberDiscount->uuid, $extra->uuid]],
        ]);

        $this->book(['discount_program_uuid' => $extra->uuid])->assertSuccessful();
    }
}
