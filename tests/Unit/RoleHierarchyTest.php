<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// The business roles stack: the owner can do what a manager can, and a
// manager what the front desk can. Two places could break that without any
// one change looking wrong — a permission key added to a lower role's bundle
// only, and a business/* route whose role gate lets a lower role in but not a
// higher one. Neither needs a database: this reads config/permission.php and
// the registered routes.
class RoleHierarchyTest extends TestCase
{
    private const ORDER = ['front_officer', 'manager', 'business_owner'];

    // Routes meant for one role only, on purpose (not something a higher role
    // is missing): a manager's own notification switches, and a manager asking
    // the owner for a new program (the owner answers it).
    private const ON_PURPOSE = [
        'GET|HEAD api/business/notification-preferences',
        'PATCH api/business/notification-preferences',
        'POST api/business/program-requests',
    ];

    public function test_every_permission_of_a_lower_role_is_held_by_the_roles_above_it(): void
    {
        for ($i = 0; $i < count(self::ORDER) - 1; $i++) {
            $lower = config('permission.' . self::ORDER[$i]);
            for ($j = $i + 1; $j < count(self::ORDER); $j++) {
                $higher = config('permission.' . self::ORDER[$j]);
                $this->assertSame(
                    [],
                    array_values(array_diff($lower, $higher)),
                    self::ORDER[$j] . ' lacks permissions that ' . self::ORDER[$i] . ' has.',
                );
            }
        }
    }

    public function test_no_business_route_lets_a_lower_role_in_but_not_a_higher_one(): void
    {
        $gaps = [];
        $reachedByFrontDesk = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/business')) {
                continue;
            }

            $roles = null;
            $keys = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }
                if (str_contains($middleware, 'RoleMiddleware:')) {
                    $these = explode(',', substr($middleware, strpos($middleware, 'RoleMiddleware:') + strlen('RoleMiddleware:')));
                    $roles = $roles === null ? $these : array_values(array_intersect($roles, $these));
                } elseif (str_contains($middleware, 'EnsurePermission:')) {
                    $keys[] = substr($middleware, strpos($middleware, 'EnsurePermission:') + strlen('EnsurePermission:'));
                } elseif (str_starts_with($middleware, 'role:')) {
                    $these = explode(',', substr($middleware, 5));
                    $roles = $roles === null ? $these : array_values(array_intersect($roles, $these));
                } elseif (str_starts_with($middleware, 'permission:')) {
                    $keys[] = substr($middleware, 11);
                }
            }

            $passes = fn (string $role): bool => ($roles === null || in_array($role, $roles, true))
                && array_diff($keys, config('permission.' . $role)) === [];

            if ($passes('front_officer')) {
                $reachedByFrontDesk++;
            }

            for ($i = 0; $i < count(self::ORDER) - 1; $i++) {
                if (! $passes(self::ORDER[$i])) {
                    continue;
                }
                for ($j = $i + 1; $j < count(self::ORDER); $j++) {
                    if (! $passes(self::ORDER[$j])) {
                        $label = implode('|', $route->methods()) . ' ' . $uri;
                        if (! in_array($label, self::ON_PURPOSE, true)) {
                            $gaps[] = "{$label}: {$this->label(self::ORDER[$i])} can, {$this->label(self::ORDER[$j])} cannot";
                        }
                    }
                }
            }
        }

        // Reading the routes worked (the reschedule route is one of them).
        $this->assertGreaterThan(40, $reachedByFrontDesk);
        $this->assertSame([], array_values(array_unique($gaps)), "A higher role is refused something a lower role may do:\n" . implode("\n", array_unique($gaps)));
    }

    private function label(string $role): string
    {
        return str_replace('_', ' ', $role);
    }
}
