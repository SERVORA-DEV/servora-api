<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 'plan.feature:reward_access' — the business's current subscription plan
// must include the feature (subscription_plans boolean column). Checked for
// every business role: a manager or front desk account works under the
// owner's plan. 403 with code PLAN_FEATURE_REQUIRED so the web can show an
// upgrade prompt instead of a generic error.
class EnsurePlanFeature
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private SubscriptionRepository $subscriptionRepository,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $business = $request->user() ? $this->spaBusinessRepository->findForUser($request->user()) : null;
        $graceDays = SystemSetting::current()->subscription_grace_period_days;
        $subscription = $business
            ? $this->subscriptionRepository->findActiveOrInGraceForBusiness($business->id, $graceDays)
            : null;

        if (! $subscription?->plan?->{$feature}) {
            return response()->json([
                'message' => 'Your plan doesn\'t include customer programs. Upgrade to use them.',
                'code' => 'PLAN_FEATURE_REQUIRED',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
