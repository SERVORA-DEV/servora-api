<?php

namespace App\Http\Middleware;

use App\Repository\SpaBusinessRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verification doesn't keep an owner out of Servora: an unverified owner can
// subscribe and run the spa, and only stays off the marketplace
// (MarketplaceReadiness). What this stops is an account with no business yet
// (it hasn't answered Verify now / Not now, and nearly every route needs one)
// and a suspended business. The frontend's redirect (middleware/verified.global.ts
// in servora-web) is UX only, since nothing stops a direct URL hit. Returns
// 423 Locked (not 403) so the frontend can tell this apart from "wrong role".
class EnsureBusinessUsable
{
    public function __construct(private SpaBusinessRepository $spaBusinessRepository) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['business_owner', 'manager'], true)) {
            return $next($request);
        }

        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json([
                'message' => 'Set up your business first.',
                'code' => 'VERIFICATION_REQUIRED',
            ], 423);
        }

        if ($business->verification_status === 'Suspended') {
            return response()->json([
                'message' => 'This business is suspended.',
                'code' => 'BUSINESS_SUSPENDED',
            ], 423);
        }

        return $next($request);
    }
}
