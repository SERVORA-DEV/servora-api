<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Client;
use App\Models\OwnerIdentityVerification;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Billing follows Settings → Payments: the payment window is offered only
// the methods the owner switched on, and the API refuses any other
// (BillingService::enabledMethods / paymentRejection). Online payments are
// gone, so "Online Banking" and "QR Code" can never be taken.
//
// Needs PostgreSQL (see phpunit.xml):
//   DB_PASSWORD=... php artisan test --filter=AcceptedPaymentMethodsTest
class AcceptedPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $this->owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);

        $plan = SubscriptionPlan::create([
            'category' => 'Basic', 'name' => 'Basic', 'monthly_price' => 500, 'billing_cycle' => 'Monthly',
            'max_branches' => 5, 'max_user_accounts' => 10, 'is_active' => true,
        ]);
        Subscription::create([
            'spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'billing_cycle' => 'Monthly',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->owner);
    }

    private function accept(array $methods): void
    {
        $this->patchJson('/api/business/settings/payments', array_merge([
            'accept_cash' => false, 'accept_gcash' => false, 'accept_paymaya' => false,
            'accept_card' => false, 'accept_bank_transfer' => false,
        ], $methods))->assertOk();
    }

    private function bill(float $amount = 1000): Billing
    {
        $client = Client::factory()->create(['spa_business_id' => $this->business->id]);
        $appointment = Appointment::create([
            'spa_branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'appointment_number' => 'APT-' . uniqid(),
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '10:00',
            'appointment_type' => 'Reservation',
            'source' => 'Front Desk',
            'status' => Appointment::STATUS_IN_SERVICE,
            'subtotal' => $amount,
            'total_amount' => $amount,
        ]);

        return Billing::create([
            'appointment_id' => $appointment->id,
            'spa_business_id' => $this->business->id,
            'spa_branch_id' => $this->branch->id,
            'billing_type' => 'Appointment',
            'billing_number' => 'BIL-' . uniqid(),
            'subtotal' => $amount,
            'amount' => $amount,
            'status' => 'Pending',
            'issued_at' => now(),
        ]);
    }

    private function pay(Billing $bill, string $method, float $amount = 1000)
    {
        return $this->postJson("/api/business/billing/{$bill->uuid}/payments", [
            'payment_method' => $method,
            'amount' => $amount,
            'reference_number' => $method === 'Cash' ? null : 'REF-12345',
        ]);
    }

    public function test_the_payment_window_is_offered_only_the_switched_on_methods(): void
    {
        $this->accept(['accept_cash' => true, 'accept_card' => true]);

        $methods = collect($this->getJson('/api/business/payment-options')->assertOk()->json('data'))->pluck('method')->all();

        $this->assertSame(['Cash', 'Credit Card', 'Debit Card'], $methods);
    }

    public function test_every_switched_on_method_can_be_taken(): void
    {
        $this->accept([
            'accept_cash' => true, 'accept_gcash' => true, 'accept_paymaya' => true,
            'accept_card' => true, 'accept_bank_transfer' => true,
        ]);

        foreach (['Cash', 'GCash', 'Maya', 'Credit Card', 'Debit Card', 'Bank Transfer'] as $method) {
            $this->pay($this->bill(), $method)->assertSuccessful();
        }
    }

    public function test_a_switched_off_method_is_refused(): void
    {
        $this->accept(['accept_cash' => true]);

        $this->pay($this->bill(), 'GCash')->assertStatus(422);
        $this->pay($this->bill(), 'Bank Transfer')->assertStatus(422);
    }

    public function test_online_payment_methods_can_no_longer_be_taken(): void
    {
        $this->accept([
            'accept_cash' => true, 'accept_gcash' => true, 'accept_paymaya' => true,
            'accept_card' => true, 'accept_bank_transfer' => true,
        ]);

        $this->pay($this->bill(), 'Online Banking')->assertStatus(422);
        $this->pay($this->bill(), 'QR Code')->assertStatus(422);
    }

    public function test_a_wallet_or_card_payment_needs_its_reference_number(): void
    {
        $this->accept(['accept_gcash' => true]);

        $this->postJson("/api/business/billing/{$this->bill()->uuid}/payments", [
            'payment_method' => 'GCash',
            'amount' => 1000,
        ])->assertStatus(422);
    }
}
