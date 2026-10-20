<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsurePermission;
use App\Models\UserPermission;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

// The permissions an owner switches on and off per account (Staff Accounts →
// Access) only mean something if every route checks them. Reads the real
// route list; no database.
class PermissionCoverageTest extends TestCase
{
    // Self-service actions of the signed-in account: its own notifications,
    // password and notification switches. There is nothing to grant or take
    // away.
    private const SELF_SERVICE = [
        'SecurityController@changePassword',
        'NotificationController@markRead',
        'NotificationController@markAllRead',
        'NotificationController@destroy',
        'NotificationController@clearRead',
        'ManagerNotificationPrefsController@update',
    ];

    private const ACCOUNT_ROLES = ['manager', 'front_officer'];

    /** @return array<int, array{route: Route, roles: string[], keys: string[], action: string}> */
    private function businessRoutes(): array
    {
        $out = [];
        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/business/')) {
                continue;
            }
            $roles = null;
            $keys = [];
            foreach ($route->gatherMiddleware() as $mw) {
                if (! is_string($mw)) {
                    continue;
                }
                if (str_contains($mw, 'RoleMiddleware:') || str_starts_with($mw, 'role:')) {
                    $set = explode(',', substr($mw, strpos($mw, ':') + 1));
                    $roles = $roles === null ? $set : array_values(array_intersect($roles, $set));
                } elseif (str_starts_with($mw, 'permission:')) {
                    $keys[] = substr($mw, strlen('permission:'));
                } elseif (str_starts_with($mw, EnsurePermission::class . ':')) {
                    $keys[] = substr($mw, strlen(EnsurePermission::class) + 1);
                }
            }
            if ($roles !== null) {
                $out[] = ['route' => $route, 'roles' => $roles, 'keys' => $keys, 'action' => class_basename($route->getActionName())];
            }
        }

        return $out;
    }

    public function test_every_change_a_manager_or_front_desk_can_make_checks_a_permission(): void
    {
        $open = [];
        foreach ($this->businessRoutes() as $r) {
            $writes = array_diff($r['route']->methods(), ['GET', 'HEAD']);
            if (! $writes || ! array_intersect($r['roles'], self::ACCOUNT_ROLES)) {
                continue;
            }
            if (! $r['keys'] && ! in_array($r['action'], self::SELF_SERVICE, true)) {
                $open[] = implode('|', $writes) . ' ' . $r['route']->uri() . ' [' . $r['action'] . ']';
            }
        }

        $this->assertSame([], $open, "Routes a manager or front desk can write to with no permission check:\n" . implode("\n", $open));
    }

    public function test_a_permission_never_locks_out_a_role_the_route_lets_in(): void
    {
        $dead = [];
        foreach ($this->businessRoutes() as $r) {
            foreach ($r['roles'] as $role) {
                foreach ($r['keys'] as $key) {
                    if (! in_array($key, config("permission.{$role}", []), true)) {
                        $dead[] = "{$role} {$key} " . $r['route']->uri();
                    }
                }
            }
        }

        $this->assertSame([], $dead, "A role is let in by the route but its bundle lacks the key:\n" . implode("\n", $dead));
    }

    public function test_every_key_of_every_role_can_be_stored_per_account(): void
    {
        $storable = (new UserPermission())->getFillable();

        foreach (['system_administrator', 'manager', 'front_officer'] as $role) {
            $this->assertSame([], array_values(array_diff(config("permission.{$role}"), $storable)), "{$role}: keys with no user_permissions column");
        }
    }

    public function test_newly_enforced_keys_are_in_their_roles_bundle_and_default_on(): void
    {
        foreach (config('permission.newly_enforced') as $role => $keys) {
            foreach ($keys as $key) {
                $this->assertContains($key, config("permission.{$role}"), "{$role} {$key}");
                $this->assertNotContains($key, config("permission.default_off.{$role}", []), "{$role} {$key} would start off");
            }
        }
    }
}
