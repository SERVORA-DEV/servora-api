<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBusinessSubscribed;
use App\Models\Client;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingClientSearchTest extends TestCase
{
    public function test_the_appointment_lookup_returns_only_spa_clients_before_pagination(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        // Older SQLite builds lack the concat used by the full-name search.
        DB::connection()->getPdo()->sqliteCreateFunction('concat', fn (...$parts) => implode('', $parts));
        Schema::disableForeignKeyConstraints();
        foreach ([
            '2026_01_01_000120_create_users_table.php',
            '2026_01_01_000190_create_spa_businesses_table.php',
            '2026_01_01_000310_create_spa_branches_table.php',
            '2026_01_01_000530_create_clients_table.php',
            '2026_01_01_000650_create_appointments_table.php',
            '2026_01_01_000710_create_billings_table.php',
            '2026_01_01_000720_create_client_memberships_table.php',
            '2026_01_01_000760_create_client_vouchers_table.php',
            '2026_11_27_010000_add_participant_origin_to_clients.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->withoutMiddleware(EnsureBusinessSubscribed::class);

        $business = SpaBusiness::factory()->create();
        $branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $otherBranch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $officer = User::factory()->create(['role' => 'front_officer']);
        $officer->setRelation('scopeBranches', collect([$branch]));
        $officer->setRelation('scopeLevels', collect([$branch->id => 'operation']));
        $officer->setRelation('permission', new UserPermission(['client_view' => true]));
        Sanctum::actingAs($officer);

        $defaults = ['spa_business_id' => $business->id, 'spa_branch_id' => $branch->id, 'last_name' => 'Cruz'];
        $app = Client::create(['first_name' => 'Ana', 'user_id' => User::factory()->create(['role' => 'client'])->id] + $defaults);
        $spa = Client::create(['first_name' => 'Bella'] + $defaults);
        $participant = Client::create(['first_name' => 'Cara', 'created_as_participant' => true] + $defaults);
        Client::create(['first_name' => 'Alicia', 'spa_branch_id' => $otherBranch->id] + $defaults);

        $this->getJson('/api/business/client?q=Cruz&segment=spa&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.uuid', $spa->uuid)
            ->assertJsonPath('data.0.uses_app', false);
        $this->getJson('/api/business/client?q=Cruz&segment=spa&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.uuid', $participant->uuid)
            ->assertJsonPath('data.0.created_as_participant', true);
        $this->getJson('/api/business/client?q=Cruz')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.uuid', $app->uuid)
            ->assertJsonPath('data.0.uses_app', true);
    }
}
