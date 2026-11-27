<?php

namespace Tests\Feature;

use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use App\Service\Business\ClientService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientProfileTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');

        // This fixture exercises client and booking data without building the rest of a spa.
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000530_create_clients_table.php',
            '2026_01_01_000650_create_appointments_table.php',
            '2026_01_01_000660_create_appointment_packages_table.php',
            '2026_01_01_000360_create_audit_logs_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_only_a_new_companion_profile_is_marked_and_keeps_its_origin_when_linked_to_the_app(): void
    {
        $this->migrateParticipantOrigin();
        $service = app(ClientService::class);
        $details = ['first_name' => 'Mia', 'last_name' => 'Cruz', 'phone_number' => '09170000001'];

        $spaClient = $service->findOrCreate(1, $details);
        $reused = $service->findOrCreate(1, $details, createdAsParticipant: true);
        $this->assertSame($spaClient->id, $reused->id);
        $this->assertTags($reused, usesApp: false, participant: false);

        $details['phone_number'] = '09170000002';
        $companion = $service->findOrCreate(1, $details, createdAsParticipant: true);
        $this->assertTags($companion->refresh(), usesApp: false, participant: true);

        $user = User::factory()->create(['role' => 'client', 'email' => 'mia@example.test']);
        $linked = $service->findOrCreateForUser(1, $user, $details);
        $this->assertSame($companion->id, $linked->id);
        $this->assertTags($linked, usesApp: true, participant: true);
    }

    public function test_backfill_marks_a_profile_created_during_its_first_shared_mobile_booking(): void
    {
        $payer = $this->profile('2026-11-01 10:00:00');
        $participant = $this->profile('2026-11-02 10:00:03');
        $existing = $this->profile('2026-11-01 10:00:00');
        $spaCreated = $this->profile('2026-11-02 10:00:03');
        $pastVisitor = $this->profile('2026-11-02 10:00:02');

        $this->appointment($pastVisitor, '2026-11-02 10:00:02', 'Front Desk');
        DB::table('audit_logs')->insert(['table_name' => 'clients', 'record_id' => $spaCreated, 'action' => 'Create']);

        $purchase = $this->place($this->appointment($payer, '2026-11-02 10:00:01'), 1);
        foreach ([$participant, $existing, $spaCreated, $pastVisitor] as $index => $guest) {
            $this->place($this->appointment($guest, '2026-11-02 10:00:04'), $index + 2, $purchase);
        }

        $this->migrateParticipantOrigin();

        $this->assertSame([$participant], Client::where('created_as_participant', true)->pluck('id')->all());
    }

    private function migrateParticipantOrigin(): void
    {
        (require database_path('migrations/2026_11_27_010000_add_participant_origin_to_clients.php'))->up();
    }

    private function assertTags(Client $client, bool $usesApp, bool $participant): void
    {
        $profile = (new ClientResource($client))->toArray(Request::create('/'));
        $this->assertSame($usesApp, $profile['uses_app']);
        $this->assertSame($participant, $profile['created_as_participant']);
    }

    private function profile(string $createdAt): int
    {
        return Client::forceCreate(['spa_business_id' => 1, 'first_name' => 'Test', 'last_name' => 'Client', 'created_at' => $createdAt])->id;
    }

    private function appointment(int $client, string $createdAt, string $source = 'Mobile'): int
    {
        $uuid = (string) Str::uuid();

        return DB::table('appointments')->insertGetId([
            'uuid' => $uuid, 'client_id' => $client, 'spa_branch_id' => 1, 'appointment_number' => $uuid,
            'appointment_type' => 'Reservation', 'appointment_date' => '2026-11-03', 'appointment_time' => '14:00:00',
            'source' => $source, 'created_at' => $createdAt,
        ]);
    }

    private function place(int $appointment, int $slot, ?int $sharedFrom = null): int
    {
        return DB::table('appointment_packages')->insertGetId([
            'uuid' => (string) Str::uuid(), 'appointment_id' => $appointment, 'package_id' => 1, 'subtotal' => 0,
            'unit_price' => 0, 'client_slot' => $slot, 'shared_from_id' => $sharedFrom,
        ]);
    }
}
