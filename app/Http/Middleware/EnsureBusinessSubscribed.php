<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Runs after 'verified.business' in the same route group, so a business
// here is already known to exist and be verified. Only gates
// business_owner — manager accounts have never had access to billing
// (see the owner-only subscription/plans routes) and stay unaffected here
// too, same as the frontend's verified.global.ts scoping. 402 (not 423,
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
        private SubscriptionRepository $subscriptionRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'business_owner') {
            return $next($request);
        }

        $business = $this->spaBusinessRepository->findForUser($user);
        $graceDays = SystemSetting::current()->subscription_grace_period_days;

        $activeSubscription = $business
            ? $this->subscriptionRepository->findActiveOrInGraceForBusiness($business->id, $graceDays)
            : null;

        if (! $activeSubscription) {
            return response()->json([
                'message' => 'An active subscription is required to access the dashboard.',
                'code' => 'SUBSCRIPTION_REQUIRED',
            ], 402);
        }

        return $next($request);
    }
}
