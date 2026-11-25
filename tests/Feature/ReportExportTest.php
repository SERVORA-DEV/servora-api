<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\Client;
use App\Models\OwnerIdentityVerification;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The file a plan with limited exports gets (GET /business/reports/export-data) must say what the
// Reports page says: finished visits only for clients, services and staff, amounts after discounts and
// net of what was handed back, the page's own words for a status, cancelled bills left out.
class ReportExportTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    private SpaBusiness $business;
    private SpaBranch $branch;
    /** @var array<string, ServiceVariant> */
    private array $variants = [];

    public function refreshDatabase(): void
    {
        if (config('database.connections.'.config('database.default').'.database') !== 'servora_test') {
            throw new \RuntimeException('These tests require the isolated servora_test database.');
        }
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'business_owner']);
        OwnerIdentityVerification::create(['user_id' => $owner->id, 'status' => 'Verified']);
        $this->business = SpaBusiness::factory()->create(['owner_id' => $owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id, 'branch_name' => 'Makati']);
        $plan = SubscriptionPlan::create([
            'name' => 'Basic', 'category' => 'Basic', 'monthly_price' => 499, 'yearly_price' => 4990, 'billing_cycle' => 'Both',
            'max_branches' => 1, 'max_user_accounts' => 3, 'mobile_app_access' => true, 'report_access' => true, 'report_export' => true,
            'export_mode' => 'csv', 'export_days' => 30, 'extra_account_price' => 49, 'is_active' => true,
        ]);
        Subscription::create(['spa_business_id' => $this->business->id, 'subscription_plan_id' => $plan->id, 'status' => 'Active',
            'starts_at' => now(), 'expires_at' => now()->addMonth(), 'billing_cycle' => 'Monthly', 'base_paid' => 499]);
        Sanctum::actingAs($owner);
    }

    /** A visit today; $line is what its one service came to; $bill [amount, status, paid, refunded, discount] or null. */
    private function visit(string $client, string $status, float $line, ?array $bill = null, string $service = 'Swedish Massage'): Appointment
    {
        $person = Client::factory()->create(['spa_business_id' => $this->business->id, 'first_name' => $client, 'last_name' => 'Test']);
        $appointment = Appointment::create([
            'spa_branch_id' => $this->branch->id, 'client_id' => $person->id, 'appointment_number' => 'APT-'.uniqid(),
            'appointment_date' => now()->format('Y-m-d'), 'appointment_time' => '10:00', 'appointment_type' => 'Walk-in',
            'source' => 'Front Desk', 'status' => $status, 'total_amount' => $line,
        ]);
        $variant = $this->variants[$service] ??= ServiceVariant::factory()->create([
            'service_id' => Service::factory()->create(['spa_business_id' => $this->business->id, 'name' => $service])->id,
        ]);
        AppointmentServiceItem::create([
            'appointment_id' => $appointment->id, 'service_variant_id' => $variant->id,
            'quantity' => 1, 'unit_price' => $line, 'subtotal' => $line, 'status' => $status === 'completed' ? 'Completed' : 'Pending', 'sort_order' => 1,
        ]);
        if ($bill) {
            [$amount, $billStatus, $paid, $refunded, $discount] = $bill + [4 => 0];
            $billing = Billing::create([
                'appointment_id' => $appointment->id, 'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id,
                'billing_type' => 'Appointment', 'billing_number' => 'BIL-'.uniqid(), 'amount' => $amount, 'status' => $billStatus,
                'issued_at' => now(), 'discount_amount' => $discount,
            ]);
            if ($paid > 0) {
                Payment::create(['billing_id' => $billing->id, 'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id,
                    'payment_method' => 'Cash', 'amount' => $paid, 'payment_status' => 'Paid', 'paid_at' => now(), 'refunded_amount' => $refunded ?: null]);
            }
        }

        return $appointment;
    }

    private function export(string $type, string $extra = ''): array
    {
        $today = now()->format('Y-m-d');
        $res = $this->getJson("/api/business/reports/export-data?format=csv&type={$type}&date_from={$today}&date_to={$today}{$extra}")->assertOk();
        $table = $res->json('data.tables.0');

        return ['columns' => array_column($table['columns'], 'label'), 'kinds' => array_column($table['columns'], 'kind'), 'rows' => $table['rows']];
    }

    public function test_sales_say_what_the_page_says_and_leave_cancelled_bills_out(): void
    {
        $this->visit('Ana', 'completed', 1000, [900, 'Paid', 900, 0, 100]);
        $this->visit('Bea', 'completed', 500, [500, 'Pending', 200, 0, 0]);
        $this->visit('Cai', 'completed', 800, [800, 'Paid', 800, 300, 0]);
        $this->visit('Dee', 'cancelled', 600, [600, 'Cancelled', 0, 0, 0]);

        $sales = $this->export('sales');
        $this->assertSame(['Bill', 'Date', 'Client', 'Branch', 'Status', 'Total', 'Paid', 'Unpaid'], $sales['columns']);
        $this->assertSame(['text', 'date', 'text', 'text', 'text', 'money', 'money', 'money'], $sales['kinds']);
        $byClient = collect($sales['rows'])->keyBy(2);
        $this->assertCount(3, $sales['rows'], 'the cancelled bill is not listed');
        $this->assertEquals(['Paid', 900, 900, 0], [$byClient['Ana Test'][4], $byClient['Ana Test'][5], $byClient['Ana Test'][6], $byClient['Ana Test'][7]]);
        $this->assertEquals(['Partly paid', 500, 200, 300], [$byClient['Bea Test'][4], $byClient['Bea Test'][5], $byClient['Bea Test'][6], $byClient['Bea Test'][7]]);
        $this->assertEquals(500, $byClient['Cai Test'][6], 'paid is net of what was handed back (800 - 300)');

        $unpaid = $this->export('sales', '&list_filter=unpaid');
        $this->assertSame(['Bea Test'], array_column($unpaid['rows'], 2));
    }

    public function test_appointments_use_the_pages_words_and_the_amount_after_discounts(): void
    {
        $this->visit('Ana', 'completed', 1000, [900, 'Paid', 900, 0, 100]);
        $this->visit('Bea', 'no_show', 700);
        $this->visit('Cai', 'in_service', 400);

        $rows = collect($this->export('appointments')['rows'])->keyBy(3);
        $this->assertSame('Completed', $rows['Ana Test'][5]);
        $this->assertEquals(900, $rows['Ana Test'][6], 'the bill, after the discount, not the 1000 list price');
        $this->assertSame('No-show', $rows['Bea Test'][5]);
        $this->assertSame('In service', $rows['Cai Test'][5]);
    }

    public function test_clients_services_and_branches_count_finished_visits_only(): void
    {
        $this->visit('Ana', 'completed', 1000, [900, 'Paid', 900, 0, 100], 'Hot Stone');
        $this->visit('Bea', 'completed', 500, null, 'Hot Stone');
        $this->visit('Cai', 'cancelled', 600, null, 'Hot Stone');
        $this->visit('Dee', 'no_show', 700, null, 'Body Scrub');

        $clients = $this->export('clients');
        $this->assertEqualsCanonicalizing(['Ana Test', 'Bea Test'], array_column($clients['rows'], 0), 'a cancelled or missed visit is not a client served');

        $services = $this->export('services');
        $this->assertEquals([['Hot Stone', 'Service', 2, 1500, 750]], $services['rows']);

        $branches = $this->export('branches');
        // 4 visits, 1 cancelled: 3 appointments, 2 finished, 1 cancelled; paid is what the one bill took.
        $this->assertEquals([['Makati', 900, 3, 2, 1, 700]], $branches['rows']);
    }

    public function test_every_accessible_branch_is_listed_even_without_visits(): void
    {
        SpaBranch::factory()->create(['spa_business_id' => $this->business->id, 'branch_name' => 'Quezon City']);

        $names = array_column($this->export('branches')['rows'], 0);
        $this->assertEqualsCanonicalizing(['Makati', 'Quezon City'], $names);
    }
}
