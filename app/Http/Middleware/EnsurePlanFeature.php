<?php

namespace App\Http\Middleware;

use App\Support\AppCache;
use App\Repository\SpaBusinessRepository;
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
    private const NAMES = [
        'reward_access' => 'customer programs',
        'package_access' => 'packages',
    ];

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
    ) {}

    // 'plan.feature:reward_access,open': for work that is already open (a bill being
    // paid, a visit being served), the feature keeps working for a short while after
    // the plan ends (AppCache::planAllowsOpenWork).
    public function handle(Request $request, Closure $next, string $feature, ?string $mode = null): Response
    {
        $business = $request->user() ? $this->spaBusinessRepository->findForUser($request->user()) : null;

        $allowed = $business && ($mode === 'open'
            ? AppCache::planAllowsOpenWork($business->id, $feature)
            : AppCache::planAllows($business->id, $feature));
        if (! $allowed) {
            return response()->json([
                'message' => 'Your plan doesn\'t include ' . (self::NAMES[$feature] ?? 'this feature') . '. Upgrade to use them.',
                'code' => 'PLAN_FEATURE_REQUIRED',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
