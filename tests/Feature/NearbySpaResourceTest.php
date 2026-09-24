<?php

namespace Tests\Feature;

use App\Models\BranchPackage;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Service\Business\SpaBranchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Covers the filter fields GET /spas/nearby carries for the client app's
// Explore screen: open now, service categories and starting price. They must
// be built from the same public-only services/packages the branch-detail
// endpoint lists, or a browse card would advertise something the details
// screen it opens doesn't offer.
//
// Needs the real database engine: several migrations use engine-specific raw
// SQL, so sqlite :memory: cannot build this schema. phpunit.xml points at a
// local Postgres `servora_test` database — never run this against a shared
// one, since RefreshDatabase drops every table.
//
//   php artisan test --filter=NearbySpaResourceTest
class NearbySpaResourceTest extends TestCase
{
    use RefreshDatabase;

    // Bajada, Davao City — the branch sits exactly here, so it is always
    // inside the default search radius.
    private const LAT = 7.0906;
    private const LNG = 125.6131;

    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = SpaBusiness::factory()->create();
        $this->branch = SpaBranch::factory()->create([
            'spa_business_id' => $this->business->id,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The one seeded branch's row from GET /spas/nearby. */
    private function row(): array
    {
        $rows = app(SpaBranchService::class)
            ->nearby(self::LAT, self::LNG, null, null)
            ->toArray(request());

        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private function offer(string $category, float $price, array $branchService = [], array $service = []): void
    {
        $variant = ServiceVariant::factory()->create([
            'service_id' => Service::factory()->create(array_merge([
                'spa_business_id' => $this->business->id,
                'category' => $category,
            ], $service))->id,
            'price' => $price,
        ]);

        BranchService::factory()->create(array_merge([
            'spa_branch_id' => $this->branch->id,
            'service_variant_id' => $variant->id,
        ], $branchService));
    }

    public function test_a_branch_with_nothing_on_offer_has_no_categories_or_price(): void
    {
        $row = $this->row();

        $this->assertSame([], $row['service_categories']);
        $this->assertNull($row['starting_price']);
    }

    public function test_categories_are_distinct_and_sorted(): void
    {
        $this->offer('Massage', 800);
        $this->offer('Facial', 600);
        $this->offer('Massage', 1200);

        $this->assertSame(['Facial', 'Massage'], $this->row()['service_categories']);
    }

    public function test_services_a_stranger_cannot_book_are_left_out(): void
    {
        $this->offer('Massage', 300, ['is_available' => false]);
        $this->offer('Facial', 400, [], ['is_active' => false]);
        $this->offer('Wellness', 900);

        $row = $this->row();

        $this->assertSame(['Wellness'], $row['service_categories']);
        $this->assertSame(900.0, $row['starting_price']);
    }

    public function test_a_branch_custom_price_overrides_the_default(): void
    {
        $this->offer('Massage', 800, ['custom_price' => 450]);
        $this->offer('Facial', 600);

        $this->assertSame(450.0, $this->row()['starting_price']);
    }

    public function test_a_cheaper_package_sets_the_starting_price(): void
    {
        $this->offer('Massage', 800);

        BranchPackage::factory()->create([
            'spa_branch_id' => $this->branch->id,
            'package_id' => Package::factory()->create([
                'spa_business_id' => $this->business->id,
                'default_price' => 500,
            ])->id,
        ]);

        $this->assertSame(500.0, $this->row()['starting_price']);
    }

    public function test_open_now_follows_the_branch_schedule(): void
    {
        // A Wednesday.
        BranchSchedule::create([
            'spa_branch_id' => $this->branch->id,
            'day_of_week' => 'Wednesday',
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
            'is_closed' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2027-03-03 10:00:00'));
        $open = $this->row();
        $this->assertTrue($open['is_open_now']);
        $this->assertSame('6:00 PM', $open['closes_at']);

        Carbon::setTestNow(Carbon::parse('2027-03-03 20:00:00'));
        $closed = $this->row();
        $this->assertFalse($closed['is_open_now']);
        $this->assertSame('9:00 AM', $closed['opens_at']);
    }
}
