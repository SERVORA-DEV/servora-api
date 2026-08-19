<?php

namespace App\Http\Middleware;

use App\Repository\SpaBusinessRepository;
use App\Support\OwnerVerificationStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The real, backend-enforced gate — the frontend's own redirect (see
// middleware/verified.ts in servora-web) is UX only and can't be trusted to
// keep an unverified owner off the dashboard, since nothing stops a direct
// URL hit. Returns 423 Locked (not 403) so the frontend can tell "wrong
// role" apart from "right role, verification incomplete".
class EnsureBusinessVerified
{
    public function __construct(private SpaBusinessRepository $spaBusinessRepository) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['business_owner', 'manager'], true)) {
            return $next($request);
        }

        $business = $this->spaBusinessRepository->findForUser($user);

        // Identity verification belongs to the account OWNER, not whichever
        // staff account is logged in — a manager has no
        // ownerIdentityVerification row of their own, so resolve through
        // the business's owner either way.
        $owner = $business?->owner;

        $identityStatus = $owner?->ownerIdentityVerification?->status ?? 'Unregistered';
        $businessStatus = $business?->verification_status ?? 'Unregistered';
        $overall = OwnerVerificationStatus::compute($identityStatus, $businessStatus);

        if ($overall !== 'VERIFIED') {
            return response()->json([
                'message' => 'Your account verification is not yet complete.',
                'code' => 'VERIFICATION_REQUIRED',
                'overall_status' => $overall,
                'identity_status' => $identityStatus,
                'business_status' => $businessStatus,
            ], 423);
        }

        return $next($request);
    }
}
