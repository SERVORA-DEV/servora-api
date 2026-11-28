<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBusinessSubscribed;
use App\Http\Middleware\EnsurePlanFeature;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogDurationLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::disableForeignKeyConstraints();
        foreach ([
            '000120_create_users', '000190_create_spa_businesses', '000200_create_addons',
            '000270_create_packages', '000290_create_services', '000310_create_spa_branches',
            '000350_create_addon_services', '000360_create_audit_logs', '000380_create_branch_packages',
            '000440_create_package_addons', '000450_create_service_parts', '000460_create_service_variants',
            '000510_create_branch_services', '000570_create_package_services', '000660_create_appointment_packages',
            '000690_create_appointment_services', '000720_create_client_memberships',
        ] as $table) {
            (require database_path('migrations/2026_01_01_'.$table.'_table.php'))->up();
        }

        $this->withoutMiddleware([EnsureBusinessSubscribed::class, EnsurePlanFeature::class]);
        $owner = User::factory()->create(['role' => 'business_owner']);
        SpaBusiness::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($owner);
    }

    public function test_service_options_accept_six_hours_and_reject_longer_home_options(): void
    {
        $service = $this->service('Massage', 360)->assertCreated()->json('data.uuid');

        $this->patchJson("/api/business/service/{$service}", [
            'variants' => [
                ['duration_minutes' => 360, 'price' => 500, 'service_location' => 'In Spa'],
                ['duration_minutes' => 361, 'price' => 800, 'service_location' => 'Home Service'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.1.duration_minutes');
    }

    public function test_package_checks_each_clients_total_before_writing_changes(): void
    {
        $massage = $this->service('Massage', 175, home: true)->assertCreated()->json('data.uuid');
        $facial = $this->service('Facial', 360, home: true)->assertCreated()->json('data.uuid');
        $addon = $this->postJson('/api/business/addon', [
            'name' => 'Extra care', 'price' => 0, 'duration_minutes' => 10, 'all_services' => true, 'categories' => ['Massage'],
        ])->assertCreated()->json('data.uuid');

        $services = [
            ['service_uuid' => $massage, 'duration_minutes' => 175, 'home_duration_minutes' => 175, 'quantity' => 2, 'addon_uuids' => [$addon], 'client_slots' => [1]],
            ['service_uuid' => $facial, 'duration_minutes' => 360, 'home_duration_minutes' => 360, 'client_slots' => [2]],
        ];
        $package = $this->postJson('/api/business/package', [
            'name' => 'Two-client package', 'default_price' => 1000, 'client_count' => 2, 'services' => $services,
            'home_service_available' => true, 'home_price' => 1500,
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 360)->assertJsonPath('data.home_duration_minutes', 360)->json('data.uuid');

        $services[0]['duration_minutes'] = 176;
        $this->patchJson("/api/business/package/{$package}", ['name' => 'Too long', 'services' => $services])
            ->assertUnprocessable()->assertJsonPath('message', 'A package can take up to 6 hours per client, including add-ons.');
        $services[0]['duration_minutes'] = 175;
        $services[0]['home_duration_minutes'] = 176;
        $this->patchJson("/api/business/package/{$package}", ['services' => $services])
            ->assertUnprocessable()->assertJsonPath('message', 'A home-service package can take up to 6 hours per client, including add-ons.');
        $this->assertDatabaseHas('packages', ['uuid' => $package, 'name' => 'Two-client package', 'duration_minutes' => 360]);
    }

    private function service(string $name, int $minutes, bool $home = false)
    {
        $variants = [['duration_minutes' => $minutes, 'price' => 500, 'service_location' => 'In Spa']];
        if ($home) {
            $variants[] = ['duration_minutes' => $minutes, 'price' => 800, 'service_location' => 'Home Service'];
        }

        return $this->postJson('/api/business/service', [
            'name' => $name,
            'category' => 'Massage',
            'variants' => $variants,
            'home_service_available' => $home,
        ]);
    }
}
