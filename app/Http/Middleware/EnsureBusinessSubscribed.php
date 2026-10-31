<?php

namespace App\Http\Middleware;

use App\Repository\SpaBusinessRepository;
use App\Support\AppCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Runs after 'verified.business' in the same route group, so a business
// here is already known to exist and be verified. Owners and staff share
// the business's subscription access; subscription purchase/recovery routes
// remain owner-only and outside this gate. 402 (not 423,
// which verification already uses) so the frontend can tell the two
// "right role, something's missing" cases apart.
//
// A lapsed subscription still passes for the platform's configured grace
// period (SystemSetting::subscription_grace_period_days, admin-editable at
// Settings > Billing) — see findActiveOrInGraceForBusiness. Access is only
// actually cut off once that grace window has elapsed too.
class EnsureBusinessSubscribed
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['business_owner', 'manager', 'front_officer'], true)) {
            return $next($request);
        }

        $business = $this->spaBusinessRepository->findForUser($user);

        // Cached (AppCache) — this runs on every owner request.
        $activeSubscription = $business ? AppCache::activeSubscription($business->id) : null;

        if (! $activeSubscription) {
            return response()->json([
                'message' => 'An active subscription is required to access the dashboard.',
                'code' => 'SUBSCRIPTION_REQUIRED',
            ], 402);
        }

        return $next($request);
    }
}
