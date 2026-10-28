<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// An administrator without two-factor authentication can sign in, but only
// reaches their own Settings → Security (to turn it on). Everything else in
// the admin area answers 403 with `code: two_factor_required`, which the web
// app turns into a move to that page. Switched off with
// ADMIN_REQUIRE_TWO_FACTOR=false (config/admin_access.php).
class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (AdminAccess::twoFactorBlocks(
            (bool) config('admin_access.require_two_factor', true),
            $user?->role,
            $user?->two_factor_confirmed_at !== null,
            $request->is(AdminAccess::TWO_FACTOR_SETUP_PATH),
        )) {
            return response()->json([
                'message' => 'Turn on two-factor authentication in Settings → Security to use the administrator area.',
                'code' => 'two_factor_required',
            ], 403);
        }

        return $next($request);
    }
}
