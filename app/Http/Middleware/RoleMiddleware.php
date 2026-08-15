<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    // Comma-separated roles in the route definition (e.g.
    // 'role:business_owner,manager') arrive here as separate variadic
    // args — Laravel splits middleware parameters on commas automatically.
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
