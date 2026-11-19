<?php

namespace App\Http\Middleware;

use App\Exceptions\NotPermitted;
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
            throw new NotPermitted('Unauthorized.');
        }

        // A route a manager reaches but the front desk doesn't is Management
        // work: a staff login only sees the branches it has Management at
        // there (App\Support\Access\AccountScope). An inner group can only narrow it.
        if (in_array('manager', $roles, true) && ! in_array('front_officer', $roles, true)) {
            $request->attributes->set(\App\Support\Access\AccountScope::LEVEL_ATTRIBUTE, \App\Models\AccountBranch::MANAGEMENT);
        }

        return $next($request);
    }
}
