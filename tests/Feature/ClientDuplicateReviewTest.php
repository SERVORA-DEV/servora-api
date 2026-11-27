<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBusinessSubscribed;
use App\Models\Client;
use App\Models\SpaBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientDuplicateReviewTest extends TestCase
{
    private User $owner;
    private int $business;
    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000310_create_spa_branches_table.php',
            '2026_01_01_000360_create_audit_logs_table.php',
            '2026_01_01_000530_create_clients_table.php',
            '2026_01_01_000650_create_appointments_table.php',
            '2026_01_01_000660_create_appointment_packages_table.php',
            '2026_01_01_000680_create_reviews_table.php',
            '2026_01_01_000710_create_billings_table.php',
            '2026_01_01_000720_create_client_memberships_table.php',
            '2026_01_01_000730_create_client_point_entries_table.php',
            '2026_01_01_000760_create_client_vouchers_table.php',
            '2026_11_27_010000_add_participant_origin_to_clients.php',
            '2026_11_27_020000_create_client_duplicate_resolutions_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->withoutMiddleware(EnsureBusinessSubscribed::class);
        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $this->business = DB::table('spa_businesses')->insertGetId(['uuid' => Str::uuid()->toString(), 'owner_id' => $this->owner->id]);
        $this->branch = DB::table('spa_branches')->insertGetId(['uuid' => Str::uuid()->toString(), 'spa_business_id' => $this->business]);
        Sanctum::actingAs($this->owner);
    }

    public function test_staff_can_review_and_merge_a_misspelled_companion_without_changing_the_bookers_or_benefits(): void
    {
        $nicole = $this->profile('Nicole', ['notes' => 'First visit']);
        $nicol = $this->profile('Nicol', ['notes' => 'Second visit', 'current_points' => 15, 'phone_number' => '09170000001']);
        $bookerOne = $this->appointment($this->profile('Anna', ['created_as_participant' => false]));
        $bookerTwo = $this->appointment($this->profile('Rose', ['created_as_participant' => false]));
        $visitOne = $this->appointment($nicole);
        $visitTwo = $this->appointment($nicol);
        $purchaseOne = $this->place($bookerOne);
        $purchaseTwo = $this->place($bookerTwo);
        $sharedOne = $this->place($visitOne, $purchaseOne);
        $sharedTwo = $this->place($visitTwo, $purchaseTwo);
        $bill = DB::table('billings')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'billing_number' => 'BILL-1', 'appointment_id' => $visitTwo,
            'amount' => 300, 'status' => 'Paid',
        ]);
        $point = DB::table('client_point_entries')->insertGetId([
            'client_id' => $nicol->id, 'billing_id' => $bill, 'type' => 'earn', 'points' => 15, 'remaining' => 15,
        ]);
        $membership = DB::table('client_memberships')->insertGetId([
            'client_id' => $nicol->id, 'customer_program_id' => 1, 'uuid' => Str::uuid()->toString(),
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);
        $voucher = DB::table('client_vouchers')->insertGetId([
            'client_id' => $nicol->id, 'customer_program_id' => 1, 'client_membership_id' => $membership,
            'uuid' => Str::uuid()->toString(), 'source' => 'membership', 'issued_at' => now(),
        ]);
        $review = DB::table('reviews')->insertGetId([
            'client_id' => $nicol->id, 'appointment_id' => $visitTwo, 'uuid' => Str::uuid()->toString(), 'rating' => 5,
        ]);

        $this->getJson($this->url($nicole))
            ->assertOk()
            ->assertJsonPath('data.profile.visit_count', 1)
            ->assertJsonPath('data.candidates.0.profile.uuid', $nicol->uuid)
            ->assertJsonPath('data.candidates.0.reason', 'Similar name');
        $this->assertDatabaseHas('appointments', ['id' => $visitTwo, 'client_id' => $nicol->id]);

        $this->postJson($this->url($nicole)."/{$nicol->uuid}/merge", ['keep_uuid' => $nicole->uuid])
            ->assertOk()->assertJsonPath('data.kept_uuid', $nicole->uuid);
        $this->assertSoftDeleted('clients', ['id' => $nicol->id]);
        $this->assertDatabaseHas('clients', ['id' => $nicole->id, 'phone_number' => '09170000001', 'current_points' => 15, 'notes' => "First visit\n\nSecond visit"]);
        $this->assertDatabaseHas('appointments', ['id' => $visitOne, 'client_id' => $nicole->id]);
        $this->assertDatabaseHas('appointments', ['id' => $visitTwo, 'client_id' => $nicole->id]);
        $this->assertDatabaseHas('appointment_packages', ['id' => $sharedOne, 'appointment_id' => $visitOne, 'shared_from_id' => $purchaseOne]);
        $this->assertDatabaseHas('appointment_packages', ['id' => $sharedTwo, 'appointment_id' => $visitTwo, 'shared_from_id' => $purchaseTwo]);
        $this->assertDatabaseHas('billings', ['id' => $bill, 'appointment_id' => $visitTwo, 'status' => 'Paid']);
        foreach (['client_point_entries' => $point, 'client_memberships' => $membership, 'client_vouchers' => $voucher, 'reviews' => $review] as $table => $id) {
            $this->assertDatabaseHas($table, ['id' => $id, 'client_id' => $nicole->id]);
        }
        $this->assertDatabaseHas('client_duplicate_resolutions', ['first_client_id' => $nicole->id, 'second_client_id' => $nicol->id, 'kept_client_id' => $nicole->id, 'resolved_by' => $this->owner->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'client.merge', 'record_id' => $nicole->id, 'user_id' => $this->owner->id]);
        $this->getJson($this->url($nicole))->assertOk()->assertJsonCount(0, 'data.candidates');
        $this->postJson($this->url($nicole)."/{$nicol->uuid}/merge", ['keep_uuid' => $nicole->uuid])->assertNotFound();
    }

    public function test_dismissing_a_pair_hides_it_from_both_profiles_and_leaves_other_businesses_out(): void
    {
        $nicole = $this->profile('Nicole');
        $other = $this->profile('Nicole');
        $outside = $this->profile('Nicole', ['spa_business_id' => $this->business + 1]);

        $this->getJson($this->url($nicole))->assertOk()->assertJsonCount(1, 'data.candidates');
        $this->postJson($this->url($nicole)."/{$outside->uuid}/merge", ['keep_uuid' => $nicole->uuid])->assertNotFound();
        $this->postJson($this->url($nicole)."/{$other->uuid}/dismiss")->assertOk();
        $this->getJson($this->url($nicole))->assertOk()->assertJsonCount(0, 'data.candidates');
        $this->getJson($this->url($other))->assertOk()->assertJsonCount(0, 'data.candidates');
        $this->assertNotSoftDeleted('clients', ['id' => $other->id]);
    }

    public function test_a_linked_mobile_profile_must_survive_and_separate_mobile_accounts_cannot_merge(): void
    {
        $guest = $this->profile('Nicole');
        $mobile = $this->profile('Nicole', ['user_id' => User::factory()->create()->id, 'created_as_participant' => false]);
        $differentMobile = $this->profile('Nicole', ['user_id' => User::factory()->create()->id]);

        $this->getJson($this->url($guest))->assertOk()->assertJsonPath('data.candidates.0.keep_uuid', $mobile->uuid);
        $this->postJson($this->url($guest)."/{$mobile->uuid}/merge", ['keep_uuid' => $guest->uuid])->assertUnprocessable();
        $this->postJson($this->url($mobile)."/{$differentMobile->uuid}/merge", ['keep_uuid' => $mobile->uuid])->assertUnprocessable();
        $this->postJson($this->url($guest)."/{$mobile->uuid}/merge", ['keep_uuid' => $mobile->uuid])->assertOk();
        $this->assertTrue($mobile->refresh()->created_as_participant);
        $this->assertNotSoftDeleted('clients', ['id' => $differentMobile->id]);
    }

    public function test_front_desk_can_review_local_profiles_but_cannot_merge_history_from_another_branch(): void
    {
        $frontDesk = User::factory()->create(['role' => 'front_officer']);
        $frontDesk->setRelation('scopeBranches', SpaBranch::whereKey($this->branch)->get());
        $frontDesk->setRelation('permission', null);
        Sanctum::actingAs($frontDesk);
        $otherBranch = DB::table('spa_branches')->insertGetId(['uuid' => Str::uuid()->toString(), 'spa_business_id' => $this->business]);
        $nicole = $this->profile('Nicole');
        $local = $this->profile('Nicole');
        $shared = $this->profile('Nicole', ['spa_branch_id' => $otherBranch]);
        $outside = $this->profile('Nicole', ['spa_branch_id' => $otherBranch]);
        $this->appointment($shared);

        $this->getJson($this->url($nicole))->assertOk()
            ->assertJsonCount(2, 'data.candidates')
            ->assertJsonPath('data.candidates.0.profile.uuid', $local->uuid)
            ->assertJsonPath('data.candidates.0.merge_block', null)
            ->assertJsonPath('data.candidates.1.profile.uuid', $shared->uuid)
            ->assertJsonPath('data.candidates.1.merge_block', 'An owner needs to review this merge because the profiles include records from another branch.');
        $this->postJson($this->url($nicole)."/{$outside->uuid}/merge", ['keep_uuid' => $nicole->uuid])->assertNotFound();
        $this->postJson($this->url($nicole)."/{$shared->uuid}/merge", ['keep_uuid' => $nicole->uuid])->assertUnprocessable();
        $this->postJson($this->url($nicole)."/{$local->uuid}/merge", ['keep_uuid' => $nicole->uuid])->assertOk();
    }

    private function profile(string $name, array $overrides = []): Client
    {
        return Client::create($overrides + [
            'spa_business_id' => $this->business, 'spa_branch_id' => $this->branch,
            'first_name' => $name, 'last_name' => 'Cruz', 'created_as_participant' => true,
        ]);
    }

    private function url(Client $client): string
    {
        return "/api/business/client/{$client->uuid}/duplicates";
    }

    private function appointment(Client $client): int
    {
        $uuid = Str::uuid()->toString();

        return DB::table('appointments')->insertGetId([
            'uuid' => $uuid, 'appointment_number' => $uuid, 'client_id' => $client->id, 'spa_branch_id' => $this->branch,
            'appointment_type' => 'Reservation', 'appointment_date' => '2026-11-27', 'appointment_time' => '10:00:00', 'source' => 'Mobile',
        ]);
    }

    private function place(int $appointment, ?int $sharedFrom = null): int
    {
        return DB::table('appointment_packages')->insertGetId([
            'uuid' => Str::uuid()->toString(), 'appointment_id' => $appointment, 'package_id' => 1, 'subtotal' => 0,
            'unit_price' => 0, 'client_slot' => $sharedFrom ? 2 : 1, 'shared_from_id' => $sharedFrom,
        ]);
    }
}
