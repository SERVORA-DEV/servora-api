<?php

namespace Tests\Unit;

use App\Http\Requests\Business\ServiceRequest;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Package;
use App\Models\PackageServiceItem;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\BranchPackageRepository;
use App\Repository\Business\ServiceVariantRepository;
use App\Service\Business\AppointmentService;
use App\Service\Business\HomeServiceRules;
use App\Service\Client\BookingPolicy;
use App\Support\CommissionShare;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Isolated SQLite connection: never reads or migrates the configured deployment database.
class HomeCatalogPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'home_catalog_test', 'database.connections.home_catalog_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('home_catalog_test');
        Schema::create('services', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('name')->nullable();
            $t->boolean('home_service_available')->default(false);
            $t->boolean('facility_required')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('service_variants', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->unsignedBigInteger('service_id');
            $t->integer('duration_minutes');
            $t->decimal('price', 10, 2);
            $t->string('service_location')->default('In Spa');
            $t->timestamps();
            $t->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX catalog_lengths ON service_variants(service_id, service_location, duration_minutes) WHERE deleted_at IS NULL');
        Schema::create('staff', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('spa_branch_id');
            $t->string('role');
            $t->string('status');
            $t->boolean('home_service_available');
            $t->softDeletes();
        });
        Cache::put('schema:packages_by_service', true);
    }

    private function catalog(): Service
    {
        return Model::withoutEvents(function () {
            $service = Service::create(['name' => 'Massage', 'home_service_available' => true]);
            (new ServiceVariantRepository)->syncForService($service->id, [
                ['duration_minutes' => 60, 'price' => 450, 'service_location' => 'In Spa'],
                ['duration_minutes' => 90, 'price' => 950, 'service_location' => 'Home Service'],
            ]);

            return $service->load('variants');
        });
    }

    public function test_home_options_are_independent_and_removed_options_remain_in_history(): void
    {
        $service = $this->catalog();
        $spa = $service->variants->firstWhere('service_location', 'In Spa');
        $home = $service->variants->firstWhere('service_location', 'Home Service');
        Model::withoutEvents(fn () => (new ServiceVariantRepository)->syncForService($service->id, [
            ['uuid' => $spa->uuid, 'duration_minutes' => 60, 'price' => 450],
            ['duration_minutes' => 60, 'price' => 800, 'service_location' => 'Home Service'],
        ]));
        $this->assertNotNull(ServiceVariant::withTrashed()->find($home->id)->deleted_at);
        $this->assertSame('950.00', ServiceVariant::withTrashed()->find($home->id)->price);
        $this->assertCount(2, $service->fresh()->variants);
        $this->assertSame('450.00', $spa->fresh()->price);
    }

    public function test_replacing_a_home_option_at_the_same_length_preserves_its_previous_row(): void
    {
        $service = $this->catalog();
        $spa = $service->variants->firstWhere('service_location', 'In Spa');
        $home = $service->variants->firstWhere('service_location', 'Home Service');
        Model::withoutEvents(fn () => (new ServiceVariantRepository)->syncForService($service->id, [
            ['uuid' => $spa->uuid, 'duration_minutes' => 60, 'price' => 450],
            ['duration_minutes' => 90, 'price' => 1000, 'service_location' => 'Home Service'],
        ]));
        $this->assertNotNull($home->fresh()->deleted_at);
        $this->assertSame('950.00', ServiceVariant::withTrashed()->find($home->id)->price);
        $this->assertSame('1000.00', $service->fresh()->variants->firstWhere('service_location', 'Home Service')->price);
    }

    public function test_package_home_duration_is_derived_from_home_components(): void
    {
        $service = $this->catalog();
        $item = (new PackageServiceItem(['service_id' => $service->id, 'duration_minutes' => 60,
            'home_duration_minutes' => 90, 'quantity' => 2]))->setRelation('service', $service);
        $package = (new Package(['default_price' => 800, 'home_service_available' => true, 'home_price' => 1700]))
            ->setRelation('packageServiceItems', collect([$item]))->setRelation('packageAddons', collect());
        $this->assertSame(120, $package->totalMinutes());
        $this->assertSame(180, $package->totalMinutes(true));
        $this->assertSame('Home Service', $item->guideVariant(true)->service_location);
        $this->assertTrue($package->homeBookable());
        $item->home_duration_minutes = null;
        $this->assertFalse($package->homeBookable());
        $item->home_duration_minutes = 90;
        // "Facility required" is about the spa only: a home visit has no facility, so it does not stop it.
        $service->facility_required = true;
        $this->assertTrue($package->homeBookable());
    }

    public function test_booking_rejects_an_in_spa_option_for_a_home_visit(): void
    {
        $service = $this->catalog();
        $variant = $service->variants->firstWhere('service_location', 'In Spa');
        $instance = (new \ReflectionClass(AppointmentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AppointmentService::class, 'addServiceInternal');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Choose a length and price for the selected booking location.');
        $method->invoke($instance, new Appointment(['service_location' => 'Home Service']), 1,
            ['service_variant_uuid' => $variant->uuid], null, 1);
    }

    public function test_home_booking_uses_the_home_variant_and_its_branch_price(): void
    {
        $service = $this->catalog();
        $variant = $service->variants->firstWhere('service_location', 'Home Service');
        Schema::create('branch_services', function (Blueprint $t) {
            $t->id();
            $t->integer('spa_branch_id');
            $t->integer('service_variant_id');
            $t->boolean('is_available');
            $t->decimal('custom_price', 10, 2)->nullable();
            $t->softDeletes();
        });
        DB::table('branch_services')->insert(['spa_branch_id' => 1, 'service_variant_id' => $variant->id,
            'is_available' => true, 'custom_price' => 1100]);
        $repository = \Mockery::mock(AppointmentServiceRepository::class);
        $repository->shouldReceive('create')->once()->andReturnUsing(fn ($row) => new AppointmentServiceItem($row));
        $instance = (new \ReflectionClass(AppointmentService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AppointmentService::class, 'appointmentServiceRepository'))->setValue($instance, $repository);
        $line = (new \ReflectionMethod(AppointmentService::class, 'addServiceInternal'))->invoke($instance,
            (new Appointment(['service_location' => 'Home Service']))->forceFill(['id' => 1]), 1,
            ['service_variant_uuid' => $variant->uuid, 'quantity' => 2], 1, 1);
        $this->assertSame($variant->id, $line->service_variant_id);
        $this->assertSame('1100.00', $line->unit_price);
        $this->assertSame('2200.00', $line->subtotal);
        $this->assertSame(90, $variant->duration_minutes);
    }

    public function test_package_branch_updates_preserve_home_prices_unless_explicitly_changed(): void
    {
        Schema::create('branch_packages', function (Blueprint $t) {
            $t->id();
            $t->integer('package_id');
            $t->integer('spa_branch_id');
            $t->boolean('is_available');
            $t->decimal('custom_price', 10, 2)->nullable();
            $t->decimal('home_custom_price', 10, 2)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $repository = new BranchPackageRepository;
        $row = ['spa_branch_id' => 1, 'is_available' => true, 'custom_price' => 800];
        Model::withoutEvents(fn () => $repository->syncForPackage(1, [$row + ['home_custom_price' => 1400]]));
        Model::withoutEvents(fn () => $repository->syncForPackage(1, [$row]));
        $this->assertSame(1400.0, (float) DB::table('branch_packages')->value('home_custom_price'));
        Model::withoutEvents(fn () => $repository->syncForPackage(1, [$row + ['home_custom_price' => null]]));
        $this->assertNull(DB::table('branch_packages')->value('home_custom_price'));
    }

    public function test_home_commission_uses_the_new_home_price(): void
    {
        $policy = ['commission_enabled' => true, 'commission_applies_to' => 'both',
            'home_commission_enabled' => true, 'home_commission_type' => 'percentage', 'home_commission_rate' => 30,
            'default_commission_rate' => 10];
        $this->assertSame(285.0, CommissionShare::amount($policy, 950, 450, false, home: true));
        $this->assertSame(142.5, CommissionShare::amount($policy, 950, 450, false, sharedBy: 2, home: true));
        $this->assertSame(45.0, CommissionShare::amount($policy, 450, 450, false));
    }

    public function test_new_home_visit_ignores_a_legacy_fee_setting(): void
    {
        DB::table('staff')->insert(['spa_branch_id' => 1, 'role' => 'therapist', 'status' => 'active', 'home_service_available' => true]);
        $settings = new SpaBusinessSetting(['booking_defaults' => ['home_service_enabled' => true, 'home_service_fee' => 400]]);
        $business = (new SpaBusiness)->setRelation('settings', $settings);
        $branch = (new SpaBranch(['latitude' => 7.0, 'longitude' => 125.0]))->forceFill(['id' => 1])->setRelation('business', $business);
        $offer = HomeServiceRules::offer($branch);
        $this->assertTrue($offer['enabled']);
        $this->assertSame(0.0, $offer['fee']);
        $this->assertSame(0, BookingPolicy::for($branch)['home_service_fee']);
        $check = HomeServiceRules::check($branch, ['home_address' => 'Test address', 'home_latitude' => 7.0, 'home_longitude' => 125.0]);
        $this->assertTrue($check['ok']);
        $this->assertSame(0.0, $check['fee']);
    }

    public function test_lengths_may_repeat_across_locations_but_not_within_one_location(): void
    {
        Cache::put('schema:service_types', false);
        $rows = [
            ['duration_minutes' => 60, 'price' => 450, 'service_location' => 'In Spa'],
            ['duration_minutes' => 60, 'price' => 800, 'service_location' => 'Home Service'],
        ];
        $validate = function ($options) {
            $request = ServiceRequest::create('/test', 'PATCH', ['home_service_available' => true, 'variants' => $options]);
            $validator = validator($request->all(), $request->rules());
            $request->withValidator($validator);

            return $validator;
        };
        $this->assertFalse($validate($rows)->fails());
        $rows[] = $rows[1];
        $this->assertTrue($validate($rows)->fails());
        $this->assertTrue($validate([$rows[0]])->fails());
    }

    public function test_migration_preserves_existing_prices_and_requires_home_price_review(): void
    {
        DB::statement('DROP INDEX catalog_lengths');
        Schema::table('service_variants', function (Blueprint $t) {
            $t->dropColumn('service_location');
            $t->unique(['service_id', 'duration_minutes']);
        });
        foreach (['packages', 'package_services', 'branch_packages'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
        }
        DB::table('service_variants')->insert(['uuid' => 'old-option', 'service_id' => 1, 'duration_minutes' => 60, 'price' => 450]);
        DB::table('packages')->insert(['id' => 1]);
        $migration = require database_path('migrations/2026_11_10_160000_home_service_catalog_prices.php');
        $migration->up();
        $row = DB::table('service_variants')->first();
        $this->assertSame('In Spa', $row->service_location);
        $this->assertSame(450.0, (float) $row->price);
        $this->assertFalse((bool) DB::table('packages')->value('home_service_available'));
        $this->assertNull(DB::table('packages')->value('home_price'));
        DB::table('service_variants')->insert(['uuid' => 'home-option', 'service_id' => 1, 'service_location' => 'Home Service', 'duration_minutes' => 60, 'price' => 800]);
        $this->assertSame(2, DB::table('service_variants')->count());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
