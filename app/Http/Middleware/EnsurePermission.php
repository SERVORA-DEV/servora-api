<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Enforces one config/permission.php key (e.g. 'permission:appointment_checkin')
// on top of the role-level gate RoleMiddleware already applies. Additive
// only — a route this middleware isn't attached to is unaffected, so
// nothing that worked before gets more restrictive by accident. Only
// applied where a matching key actually exists in config/permission.php for
// every role in that route's group; see routes/api.php for which
// appointment actions currently have no key for front_officer and are
// therefore left at role-group-level enforcement only.
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $user = $request->user();
        $allowed = $user ? config('permission.' . $user->role, []) : [];

        if (! in_array($key, $allowed, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
