<?php

namespace Tests\Unit;

use App\Http\Requests\Business\ServiceRequest;
use App\Support\AutoAssignment;
use App\Support\CommissionShare;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Services done by two or more staff at once: each part's share of the commission, the parts a
// service may have, and who fills a part nobody picked. No database.
//   php artisan test --filter=ServicePartsTest
class ServicePartsTest extends TestCase
{
    private function policy(array $overrides = []): array
    {
        return $overrides + [
            'commission_enabled' => true,
            'commission_type' => 'percentage',
            'default_commission_rate' => 50,
            'commission_applies_to' => 'service',
        ];
    }

    public function test_each_part_earns_its_share_of_the_commission(): void
    {
        // ₱500 at 50%: ₱250 to share. 60/40 pays ₱150 and ₱100; 50/50 pays ₱125 each.
        $this->assertSame(150.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 2, null, false, 60.0));
        $this->assertSame(100.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 2, null, false, 40.0));
        $this->assertSame(125.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 2, null, false, 50.0));
        // Each at their own rate: a 40% plan on a 60% part of ₱500.
        $this->assertSame(120.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 2, 40.0, false, 60.0));
        // A fixed amount per service is split by share too.
        $fixed = $this->policy(['commission_type' => 'fixed', 'default_commission_rate' => 200]);
        $this->assertSame(120.0, CommissionShare::amount($fixed, 500.0, 500.0, false, 2, null, false, 60.0));
    }

    public function test_without_parts_the_split_stays_equal(): void
    {
        $this->assertSame(125.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 2));
        $this->assertSame(250.0, CommissionShare::amount($this->policy(), 500.0, 500.0, false, 1));
    }

    private function partsErrors(array $parts, string $type = 'time'): array
    {
        Cache::setDefaultDriver('array');
        Cache::forever('schema:service_types', true);
        Cache::forever('schema:service_parts', true);
        $input = ['service_type' => $type, 'parts' => $parts];
        $request = ServiceRequest::create('/', 'PATCH', $input);
        $rules = array_filter($request->rules(), fn ($key) => str_starts_with($key, 'parts'), ARRAY_FILTER_USE_KEY);
        $validator = validator($input, $rules, $request->messages());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->all();
    }

    public function test_a_service_has_no_parts_or_two_to_four_totalling_100(): void
    {
        $this->assertSame([], $this->partsErrors([]));
        $this->assertSame([], $this->partsErrors([['name' => 'Upper body', 'share_percent' => 60], ['name' => 'Lower body', 'share_percent' => 40]]));
        $this->assertSame([], $this->partsErrors([['name' => 'A', 'share_percent' => 33.34], ['name' => 'B', 'share_percent' => 33.33], ['name' => 'C', 'share_percent' => 33.33]]));

        $this->assertContains('A service done by several staff needs at least 2 parts.', $this->partsErrors([['name' => 'Whole body', 'share_percent' => 99]]));
        $this->assertContains('The parts\' commission shares must add up to 100% (now 90%).', $this->partsErrors([['name' => 'Upper body', 'share_percent' => 40], ['name' => 'Lower body', 'share_percent' => 50]]));
        $this->assertContains('Two parts cannot have the same name.', $this->partsErrors([['name' => 'Back', 'share_percent' => 50], ['name' => 'back', 'share_percent' => 50]]));
        $this->assertContains('A facility-only service has no staff, so it has no parts.', $this->partsErrors([['name' => 'A', 'share_percent' => 50], ['name' => 'B', 'share_percent' => 50]], 'facility'));
        $five = array_map(fn ($i) => ['name' => "P{$i}", 'share_percent' => 20], range(1, 5));
        $this->assertContains('A service can be split between at most 4 staff.', $this->partsErrors($five));
    }

    public function test_an_empty_part_goes_to_the_best_ranked_who_is_not_already_on_it(): void
    {
        $staff = [
            ['uuid' => 'ana', 'name' => 'Ana', 'workload' => 0, 'position' => 1, 'can' => ['line' => true]],
            ['uuid' => 'ben', 'name' => 'Ben', 'workload' => 1, 'position' => 2, 'can' => ['line' => true]],
            ['uuid' => 'cy', 'name' => 'Cy', 'workload' => 0, 'position' => 3, 'can' => ['line' => false]],
        ];
        // Ana already does Upper body: Lower body goes to Ben, the next who can do it.
        $this->assertSame('ben', AutoAssignment::bestFor('line', $staff, ['ana']));
        $this->assertSame('ana', AutoAssignment::bestFor('line', $staff));
        // Nobody else can do it.
        $this->assertNull(AutoAssignment::bestFor('line', $staff, ['ana', 'ben']));
    }
}
