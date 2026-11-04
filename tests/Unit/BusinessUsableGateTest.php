<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureBusinessUsable;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

// Verification no longer keeps an owner out: only having no business yet, or a
// suspended one, does. No database.
//   php artisan test --filter=BusinessUsableGateTest
class BusinessUsableGateTest extends TestCase
{
    private function gate(?string $businessStatus, string $role = 'business_owner'): int
    {
        $user = (new User())->forceFill(['role' => $role]);
        $business = $businessStatus ? (new SpaBusiness())->forceFill(['verification_status' => $businessStatus]) : null;

        $repository = Mockery::mock(SpaBusinessRepository::class);
        $repository->shouldReceive('findForUser')->andReturn($business);

        $request = Request::create('/api/business/dashboard');
        $request->setUserResolver(fn () => $user);

        return (new EnsureBusinessUsable($repository))
            ->handle($request, fn () => new Response('through'))
            ->getStatusCode();
    }

    #[DataProvider('unverifiedStatuses')]
    public function test_an_owner_who_has_not_been_verified_gets_through(string $status): void
    {
        $this->assertSame(200, $this->gate($status));
    }

    public static function unverifiedStatuses(): array
    {
        return [['Unregistered'], ['Pending'], ['Rejected'], ['Verified']];
    }

    public function test_a_manager_of_an_unverified_business_gets_through(): void
    {
        $this->assertSame(200, $this->gate('Unregistered', 'manager'));
    }

    public function test_an_owner_with_no_business_yet_is_stopped(): void
    {
        $this->assertSame(423, $this->gate(null));
    }

    public function test_a_suspended_business_is_stopped(): void
    {
        $this->assertSame(423, $this->gate('Suspended'));
        $this->assertSame(423, $this->gate('Suspended', 'manager'));
    }
}
