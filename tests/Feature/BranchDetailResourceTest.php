<?php

namespace Tests\Feature;

use App\Models\BranchService;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Service\Business\SpaBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Covers the grouped `services` shape of GET /spas/{uuid}. The mobile booking
// flow renders one card per parent service with a duration dropdown, so the
// endpoint nests a service's bookable durations under it instead of emitting
// one flat row per duration.
//
// Calls SpaBranchService::publicShow directly rather than over HTTP, the same
// way AppointmentServiceTest drives its service layer — the shaping under test
// lives entirely in the resource.
//
// Needs MySQL. phpunit.xml points at sqlite :memory:, which cannot build this
// schema at all — several migrations use raw `ALTER TABLE ... MODIFY ... ENUM`,
// which is MySQL-only. Run against the existing test database instead:
//
//   DB_CONNECTION=mysql DB_DATABASE=servora_testing DB_URL= \
//     php artisan test --filter=BranchDetailResourceTest
class BranchDetailResourceTest extends TestCase
{
    use RefreshDatabase;

    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = SpaBusiness::factory()->create();
        // publicFindByUuid 404s on anything not Verified + Active.
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'verification_status' => 'Verified',
            'operating_status' => 'Active',
        ]);
    }

    /**
     * A service offered at this branch in the given durations.
     *
     * @param  array<int, int>  $durations  minutes => price
     * @return array{0: Service, 1: array<int, BranchService>}
     */
    private function offer(string $name, array $durations, array $attributes = []): array
    {
        $service = Service::factory()->create(array_merge([
            'spa_business_id' => $this->business->id,
            'name' => $name,
        ], $attributes));

        $rows = [];
        foreach ($durations as $minutes => $price) {
            $variant = ServiceVariant::factory()->create([
                'service_id' => $service->id,
                'duration_minutes' => $minutes,
                'price' => $price,
            ]);

            $rows[$minutes] = BranchService::factory()->create([
                'spa_branch_id' => $this->branch->id,
                'service_variant_id' => $variant->id,
            ]);
        }

        return [$service, $rows];
    }

    private function services(): array
    {
        $payload = app(SpaBranchService::class)
            ->publicShow($this->branch->uuid)
            ->toArray(request());

        return $payload['services']->all();
    }

    public function test_variants_of_one_service_collapse_into_a_single_entry(): void
    {
        [$service] = $this->offer('Swedish Massage', [30 => 400, 60 => 650, 90 => 900]);

        $services = $this->services();

        $this->assertCount(1, $services);
        $this->assertSame($service->uuid, $services[0]['service_uuid']);
        $this->assertSame('Swedish Massage', $services[0]['name']);

        $variants = $services[0]['variants']->all();
        $this->assertCount(3, $variants);
        // Ascending by duration — the axis the app's dropdown labels.
        $this->assertSame([30, 60, 90], array_column($variants, 'duration_minutes'));
        $this->assertSame([400.0, 650.0, 900.0], array_column($variants, 'price'));
        // Each option is separately bookable.
        $this->assertCount(3, array_unique(array_column($variants, 'service_variant_uuid')));
    }

    public function test_the_services_key_encodes_as_a_json_array(): void
    {
        $this->offer('Swedish Massage', [30 => 400, 60 => 650]);

        $payload = app(SpaBranchService::class)
            ->publicShow($this->branch->uuid)
            ->toArray(request());

        // groupBy keys by service_id and map/sortBy preserve keys, so a missing
        // values() would encode this as an object and the client would parse no
        // services at all.
        $this->assertSame(
            '[',
            substr(json_encode($payload['services']), 0, 1),
        );
    }

    public function test_a_branch_price_override_moves_only_that_variant(): void
    {
        [, $rows] = $this->offer('Swedish Massage', [30 => 400, 60 => 650, 90 => 900]);
        $rows[60]->update(['custom_price' => 700]);

        $variants = $this->services()[0]['variants']->all();

        $this->assertSame([400.0, 700.0, 900.0], array_column($variants, 'price'));
    }

    public function test_a_variant_disabled_at_this_branch_is_omitted(): void
    {
        [, $rows] = $this->offer('Swedish Massage', [30 => 400, 60 => 650, 90 => 900]);
        $rows[60]->update(['is_available' => false]);

        $services = $this->services();

        // The service stays; only the duration the branch turned off goes. This
        // is why the resource groups branch_services rows instead of walking
        // $service->variants, which would still list the 60-minute option.
        $this->assertCount(1, $services);
        $this->assertSame([30, 90], array_column($services[0]['variants']->all(), 'duration_minutes'));
    }

    public function test_a_service_with_every_variant_disabled_disappears(): void
    {
        [, $rows] = $this->offer('Swedish Massage', [30 => 400, 60 => 650]);
        foreach ($rows as $row) {
            $row->update(['is_available' => false]);
        }

        $this->assertSame([], $this->services());
    }

    public function test_a_service_deactivated_business_wide_disappears(): void
    {
        $this->offer('Swedish Massage', [30 => 400], ['is_active' => false]);
        $this->offer('Hot Stone Therapy', [75 => 900]);

        $services = $this->services();

        $this->assertCount(1, $services);
        $this->assertSame('Hot Stone Therapy', $services[0]['name']);
    }

    public function test_the_description_is_returned(): void
    {
        $this->offer('Swedish Massage', [30 => 400], [
            'description' => 'Relaxation massage with light-to-medium pressure.',
        ]);

        $this->assertSame(
            'Relaxation massage with light-to-medium pressure.',
            $this->services()[0]['description'],
        );
    }

    public function test_services_come_back_sorted_by_name(): void
    {
        $this->offer('Swedish Massage', [60 => 650]);
        $this->offer('Aromatherapy', [60 => 700]);
        $this->offer('hot stone therapy', [75 => 900]);

        // Natural, case-insensitive — the endpoint had no ordering at all before,
        // so card order was insert order.
        $this->assertSame(
            ['Aromatherapy', 'hot stone therapy', 'Swedish Massage'],
            array_column($this->services(), 'name'),
        );
    }
}
