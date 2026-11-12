<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Client;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\SpaBranch;
use App\Repository\NotificationRepository;
use App\Service\Business\BillingService;
use App\Service\Business\ProgramRewardService;
use App\Support\AppOnlyPrograms;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

// The app-only rule applied by the real services against a disposable SQLite
// fixture: a client without the app earns nothing, can't buy with points or
// claim a voucher, and can't have a voucher or discount program put on a bill.
// The plan/switch check (enabledFor) is stubbed on; everything else is real.
class AppOnlyProgramsServiceTest extends TestCase
{
    private ProgramRewardService $rewards;

    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'app_only_programs_test', 'database.connections.app_only_programs_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array']);
        DB::purge('app_only_programs_test');
        $this->schema();

        $notifications = Mockery::mock(NotificationRepository::class);
        $notifications->shouldReceive('create')->andReturnNull();
        $this->rewards = Mockery::mock(ProgramRewardService::class, [$notifications])->makePartial();
        $this->rewards->shouldReceive('enabledFor')->andReturn(true);
        $this->app->instance(ProgramRewardService::class, $this->rewards);

        $this->branch = SpaBranch::forceCreate(['spa_business_id' => 1, 'branch_name' => 'Main']);
    }

    private function schema(): void
    {
        // Read by notifyClient (the "you earned" notice names the spa).
        Schema::create('spa_businesses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('business_name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('spa_branches', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->string('branch_name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->integer('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->integer('current_points')->default(0);
            $t->integer('lifetime_points')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('customer_programs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->string('type');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->string('audience')->nullable();
            $t->text('values')->nullable();
            $t->string('condition_kind')->nullable();
            $t->integer('condition_value')->nullable();
            $t->integer('only_branch_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('customer_program_branch', function (Blueprint $t) {
            $t->id();
            $t->integer('customer_program_id');
            $t->integer('spa_branch_id');
            $t->timestamps();
        });
        Schema::create('client_vouchers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('client_id');
            $t->integer('customer_program_id');
            $t->string('program_name')->nullable();
            $t->text('deal')->nullable();
            $t->string('source')->nullable();
            $t->integer('source_billing_id')->nullable();
            $t->integer('client_membership_id')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('status');
            $t->timestamp('used_at')->nullable();
            $t->integer('used_billing_id')->nullable();
            $t->integer('used_by')->nullable();
            $t->timestamps();
        });
        Schema::create('client_point_entries', function (Blueprint $t) {
            $t->id();
            $t->integer('client_id');
            $t->integer('billing_id')->nullable();
            $t->string('type');
            $t->integer('points');
            $t->integer('remaining')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('note')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('client_id')->nullable();
            $t->integer('spa_branch_id')->nullable();
            $t->string('source')->nullable();
            $t->string('status')->default('Scheduled');
            $t->integer('client_voucher_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('billings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->integer('spa_branch_id')->nullable();
            $t->integer('appointment_id')->nullable();
            $t->string('billing_type');
            $t->string('billing_number')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->nullable();
            $t->string('status')->default('Unpaid');
            $t->integer('client_voucher_id')->nullable();
            $t->integer('discount_program_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // Read by paidVisitServices (a client's paid visits).
        Schema::create('appointment_services', function (Blueprint $t) {
            $t->id();
            $t->integer('appointment_id');
            $t->integer('service_variant_id')->nullable();
            $t->string('status')->nullable();
        });
        Schema::create('service_variants', fn (Blueprint $t) => [$t->id(), $t->integer('service_id')]);
        Schema::create('services', fn (Blueprint $t) => [$t->id(), $t->uuid('uuid'), $t->string('category')->nullable()]);
    }

    private function client(bool $usesApp, int $points = 0): Client
    {
        return Client::forceCreate([
            'spa_business_id' => 1, 'user_id' => $usesApp ? 77 : null, 'first_name' => $usesApp ? 'Ana' : 'Walk',
            'last_name' => $usesApp ? 'App' : 'In', 'current_points' => $points, 'lifetime_points' => $points, 'is_active' => true,
        ]);
    }

    private function program(array $attrs): CustomerProgram
    {
        $program = CustomerProgram::forceCreate($attrs + ['spa_business_id' => 1, 'is_active' => true, 'audience' => 'all']);
        $program->branches()->attach($this->branch->id);

        return $program;
    }

    private function bill(Client $client, float $amount = 1000, string $status = 'Unpaid'): Billing
    {
        $appointment = Appointment::forceCreate(['client_id' => $client->id, 'spa_branch_id' => $this->branch->id, 'source' => 'Walk-in']);

        return Billing::forceCreate([
            'spa_business_id' => 1, 'spa_branch_id' => $this->branch->id, 'appointment_id' => $appointment->id,
            'billing_type' => 'Appointment', 'billing_number' => 'B-' . $appointment->id, 'amount' => $amount, 'subtotal' => $amount, 'status' => $status,
        ]);
    }

    private function holding(Client $client, CustomerProgram $program): ClientVoucher
    {
        return ClientVoucher::forceCreate([
            'client_id' => $client->id, 'customer_program_id' => $program->id, 'source' => 'spend',
            'issued_at' => now(), 'expires_at' => now()->addMonth(), 'status' => 'available',
        ]);
    }

    private function billingCall(string $method, Billing $billing, string $uuid): array|string
    {
        $service = app(BillingService::class);
        $call = new \ReflectionMethod($service, $method);

        return $call->invoke($service, $billing->fresh(['appointment.client']), $uuid);
    }

    // ── Earning ──────────────────────────────────────────────────────────

    public function test_a_paid_visit_by_a_client_without_the_app_earns_nothing(): void
    {
        $this->program(['type' => 'loyalty', 'name' => 'Points', 'values' => ['earnPoints' => 1, 'earnPerPesos' => 10]]);
        $this->program(['type' => 'voucher', 'name' => 'Spend 500', 'condition_kind' => 'min_spend', 'condition_value' => 500, 'values' => ['dealType' => '₱ off', 'value' => 100]]);
        $walkIn = $this->client(false);

        $this->rewards->onBillPaid($this->bill($walkIn, 1000, 'Paid'));

        $this->assertSame(0, ClientVoucher::where('client_id', $walkIn->id)->count());
        $this->assertSame(0, ClientPointEntry::where('client_id', $walkIn->id)->count());
        $this->assertSame(0, (int) $walkIn->fresh()->current_points);
    }

    public function test_a_paid_visit_by_an_app_user_still_earns_points_and_vouchers(): void
    {
        $this->program(['type' => 'loyalty', 'name' => 'Points', 'values' => ['earnPoints' => 1, 'earnPerPesos' => 10]]);
        $this->program(['type' => 'voucher', 'name' => 'Spend 500', 'condition_kind' => 'min_spend', 'condition_value' => 500, 'values' => ['dealType' => '₱ off', 'value' => 100]]);
        $app = $this->client(true);

        $this->rewards->onBillPaid($this->bill($app, 1000, 'Paid'));

        $this->assertSame(1, ClientVoucher::where('client_id', $app->id)->count());
        $this->assertSame(100, (int) $app->fresh()->current_points);
    }

    // ── Points → voucher ─────────────────────────────────────────────────

    public function test_a_client_without_the_app_cant_buy_a_voucher_with_points(): void
    {
        $voucher = $this->program(['type' => 'voucher', 'name' => 'Free foot spa', 'condition_kind' => 'points', 'condition_value' => 100]);
        $walkIn = $this->client(false, 500);

        $result = $this->rewards->redeemPoints($walkIn, $voucher, null, $this->branch->id);

        $this->assertSame(AppOnlyPrograms::MESSAGE, $result);
        $this->assertSame(500, (int) $walkIn->fresh()->current_points);
        $this->assertSame(0, ClientVoucher::count());
    }

    public function test_an_app_user_can_buy_a_voucher_with_points(): void
    {
        $voucher = $this->program(['type' => 'voucher', 'name' => 'Free foot spa', 'condition_kind' => 'points', 'condition_value' => 100]);
        $app = $this->client(true, 500);

        $result = $this->rewards->redeemPoints($app, $voucher, null, $this->branch->id);

        $this->assertInstanceOf(ClientVoucher::class, $result);
        $this->assertSame(400, (int) $app->fresh()->current_points);
    }

    // ── First-visit voucher ──────────────────────────────────────────────

    public function test_only_an_app_user_can_claim_a_first_visit_voucher(): void
    {
        $welcome = $this->program(['type' => 'voucher', 'name' => 'Welcome', 'condition_kind' => 'first_visit', 'values' => ['dealType' => '% off', 'value' => 10]]);
        $walkIn = $this->client(false);
        $app = $this->client(true);

        $this->assertFalse($this->rewards->firstVisitClaimable($walkIn, $welcome));
        $this->assertNull($this->rewards->claimFirstVisit($walkIn, $welcome, 'front_desk', $this->branch->id));

        $this->assertTrue($this->rewards->firstVisitClaimable($app, $welcome));
        $this->assertInstanceOf(ClientVoucher::class, $this->rewards->claimFirstVisit($app, $welcome, 'front_desk', $this->branch->id));
    }

    // ── At checkout ──────────────────────────────────────────────────────

    public function test_a_voucher_held_by_a_client_without_the_app_cant_go_on_the_bill(): void
    {
        $deal = $this->program(['type' => 'voucher', 'name' => '₱100 off', 'condition_kind' => 'min_spend', 'condition_value' => 500, 'values' => ['dealType' => '₱ off', 'value' => 100]]);
        $walkIn = $this->client(false);
        $held = $this->holding($walkIn, $deal);

        $this->assertSame(AppOnlyPrograms::MESSAGE, $this->billingCall('voucherForBill', $this->bill($walkIn), $held->uuid));
        // Kept in the wallet, not taken away.
        $this->assertSame('available', $held->fresh()->status);
    }

    public function test_an_app_users_voucher_goes_on_the_bill(): void
    {
        $deal = $this->program(['type' => 'voucher', 'name' => '₱100 off', 'condition_kind' => 'min_spend', 'condition_value' => 500, 'values' => ['dealType' => '₱ off', 'value' => 100]]);
        $app = $this->client(true);
        $held = $this->holding($app, $deal);

        $picked = $this->billingCall('voucherForBill', $this->bill($app), $held->uuid);

        $this->assertIsArray($picked);
        $this->assertSame($held->id, $picked[1]->id);
    }

    public function test_a_client_without_the_app_cant_claim_a_first_visit_voucher_at_checkout(): void
    {
        $welcome = $this->program(['type' => 'voucher', 'name' => 'Welcome', 'condition_kind' => 'first_visit', 'values' => ['dealType' => '% off', 'value' => 10]]);
        $walkIn = $this->client(false);

        $this->assertSame(AppOnlyPrograms::MESSAGE, $this->billingCall('voucherForBill', $this->bill($walkIn), $welcome->uuid));
        $this->assertSame(0, ClientVoucher::count());
    }

    public function test_a_discount_program_is_only_for_app_users(): void
    {
        $discount = $this->program(['type' => 'discount', 'name' => 'App 10%', 'values' => ['percent' => 10, 'kind' => '%']]);
        $walkIn = $this->client(false);

        $this->assertSame(AppOnlyPrograms::MESSAGE, $this->billingCall('discountProgramForBill', $this->bill($walkIn), $discount->uuid));
    }
}
