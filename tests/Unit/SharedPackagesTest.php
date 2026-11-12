<?php

namespace Tests\Unit;

use App\Http\Requests\Business\PackageRequest;
use App\Models\Package;
use App\Models\PackageServiceItem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Packages several clients share (a Couples Massage for Client A and Client B): which client gets
// which service, and the package editor's rules. No database.
//   php artisan test --filter=SharedPackagesTest
class SharedPackagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::forever('schema:shared_packages', true);
    }

    public function test_a_service_goes_to_the_clients_it_is_ticked_for(): void
    {
        $both = (new PackageServiceItem())->forceFill(['client_slots' => [1, 2]]);
        $onlyB = (new PackageServiceItem())->forceFill(['client_slots' => [2]]);
        $everyone = (new PackageServiceItem())->forceFill(['client_slots' => null]);

        $this->assertTrue($both->isFor(1));
        $this->assertTrue($both->isFor(2));
        $this->assertFalse($onlyB->isFor(1));
        $this->assertTrue($onlyB->isFor(2));
        $this->assertTrue($everyone->isFor(1));
        $this->assertTrue($everyone->isFor(3));
    }

    public function test_clients_are_named_by_letter(): void
    {
        $this->assertSame('Client A', Package::slotName(1));
        $this->assertSame('Client B', Package::slotName(2));
        $this->assertSame('Client D', Package::slotName(4));
    }

    private function editorErrors(int $clients, array $services): array
    {
        $input = ['client_count' => $clients, 'services' => $services];
        $request = PackageRequest::create('/', 'POST', $input);
        $rules = array_filter($request->rules(), fn ($key) => str_starts_with($key, 'client_count') || str_starts_with($key, 'services.*.client_slots'), ARRAY_FILTER_USE_KEY);
        $validator = validator($input, $rules, $request->messages());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->all();
    }

    public function test_every_client_needs_a_service(): void
    {
        $this->assertSame([], $this->editorErrors(2, [['service_uuid' => 'a', 'client_slots' => [1, 2]], ['service_uuid' => 'b', 'client_slots' => [2]]]));
        // Left out means every client.
        $this->assertSame([], $this->editorErrors(2, [['service_uuid' => 'a']]));
        $this->assertContains('Client B has no service in this package.', $this->editorErrors(2, [['service_uuid' => 'a', 'client_slots' => [1]]]));
    }

    public function test_a_client_beyond_the_count_is_refused(): void
    {
        $this->assertContains("Client C isn't in this package — it is for 2 clients.", $this->editorErrors(2, [['service_uuid' => 'a', 'client_slots' => [1, 3]]]));
    }
}
