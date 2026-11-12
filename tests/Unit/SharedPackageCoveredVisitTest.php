<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\Client;
use App\Support\SharedPackages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// A package several clients share (a Couples Massage): only Client A, who bought it, pays. Client
// B's visit is covered — the desk never asks them to pay, and with nothing of their own on it the
// visit closes at ₱0. A disposable SQLite fixture, no shared database.
class SharedPackageCoveredVisitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'shared_covered_test', 'database.connections.shared_covered_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array']);
        DB::purge('shared_covered_test');
        Cache::forever('schema:shared_packages', true);
        Cache::forever('schema:history', false);

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('spa_business_id');
            $t->integer('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('client_id')->nullable();
            // Read by App\Support\LivePulse when a visit is saved.
            $t->integer('spa_branch_id')->nullable();
            $t->string('status')->default('Scheduled');
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('appointment_packages', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->integer('appointment_id');
            $t->integer('package_id')->nullable();
            $t->string('package_name')->nullable();
            $t->integer('quantity')->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('status')->default('Pending');
            $t->integer('client_slot')->nullable();
            $t->integer('shared_from_id')->nullable();
            $t->timestamps();
        });
    }

    private function visit(string $first, string $last, float $total = 0): Appointment
    {
        $client = Client::forceCreate(['spa_business_id' => 1, 'first_name' => $first, 'last_name' => $last]);

        return Appointment::forceCreate(['client_id' => $client->id, 'total_amount' => $total]);
    }

    /** Client A buys a package for three; B and C join it. */
    private function couples(): array
    {
        $a = $this->visit('Andrea', 'Brillantes', 450);
        $bought = AppointmentPackage::forceCreate(['appointment_id' => $a->id, 'subtotal' => 450, 'unit_price' => 450, 'client_slot' => 1]);
        $b = $this->visit('Mia', 'Athena');
        AppointmentPackage::forceCreate(['appointment_id' => $b->id, 'client_slot' => 2, 'shared_from_id' => $bought->id]);
        $c = $this->visit('Shea', 'Valerio');
        AppointmentPackage::forceCreate(['appointment_id' => $c->id, 'client_slot' => 3, 'shared_from_id' => $bought->id]);

        return [$a, $b, $c];
    }

    public function test_the_other_clients_are_covered_by_the_one_who_bought_it(): void
    {
        [$a, $b, $c] = $this->couples();
        $other = $this->visit('Walk', 'In', 800);

        $covered = SharedPackages::coveredByFor([$a->id, $b->id, $c->id, $other->id]);

        $this->assertSame([$b->id => 'Andrea Brillantes', $c->id => 'Andrea Brillantes'], $covered);
        $this->assertTrue(SharedPackages::isCovered($b));
        $this->assertFalse(SharedPackages::isCovered($a));
        $this->assertFalse(SharedPackages::isCovered($other));
    }

    public function test_a_place_given_up_no_longer_covers_the_visit(): void
    {
        [, $b] = $this->couples();
        AppointmentPackage::where('appointment_id', $b->id)->update(['status' => 'Cancelled']);

        $this->assertFalse(SharedPackages::isCovered($b));
    }

    public function test_a_covered_visit_with_nothing_of_its_own_closes_without_a_charge(): void
    {
        $this->assertTrue(SharedPackages::closesFree(0, true));
        $this->assertTrue(SharedPackages::closesFree(0.001, true));
    }

    public function test_an_extra_service_the_covered_client_added_is_still_theirs_to_pay(): void
    {
        $this->assertFalse(SharedPackages::closesFree(300, true));
    }

    public function test_an_ordinary_visit_is_never_closed_for_free(): void
    {
        // A ₱0 visit nobody covers (everything taken off) is not a covered one.
        $this->assertFalse(SharedPackages::closesFree(0, false));
        $this->assertFalse(SharedPackages::closesFree(450, false));
    }

    public function test_nothing_is_covered_until_sharing_exists(): void
    {
        [, $b] = $this->couples();
        Cache::forever('schema:shared_packages', false);

        $this->assertSame([], SharedPackages::coveredByFor([$b->id]));
    }
}
