<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Enforces one permission key (e.g. 'permission:appointment_checkin') on top
// of the role-level gate RoleMiddleware already applies: the key must be in
// the role's config/permission.php bundle and, for manager/front_officer, on
// in the account's own user_permissions row. Additive
// only — a route this middleware isn't attached to is unaffected, so
// nothing that worked before gets more restrictive by accident. Only
// applied where a matching key actually exists in config/permission.php for
// every role in that route's group; see routes/api.php for which
// appointment actions currently have no key for front_officer and are
// therefore left at role-group-level enforcement only.
class EnsurePermission
{
    private const ACCOUNT_ROLES = ['manager', 'front_officer'];

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $user = $request->user();
        $allowed = $user ? config('permission.' . $user->role, []) : [];

        if (! in_array($key, $allowed, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        // Owner-created accounts also carry their own flags (the owner's
        // Staff Policies defaults at creation, then any per-account changes
        // in Account Management). The role bundle above is the ceiling; the
        // account's own row can only narrow it. An account with no row keeps
        // the role bundle as before.
        if (in_array($user->role, self::ACCOUNT_ROLES, true)) {
            $permission = $user->permission;

            if ($permission && $permission->getAttribute($key) !== true) {
                abort(403, 'You do not have permission to perform this action.');
            }
        }

        return $next($request);
    }
}
