<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The administrators' door and the whole /api/system area answer only to the
// addresses in ADMIN_ALLOWED_IPS (config/admin_access.php). With none set
// there is no restriction. From anywhere else the answer is "not found", the
// same as an address that does not exist.
class RestrictAdminIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminAccess::ipAllowed($request->ip(), config('admin_access.allowed_ips', []))) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
