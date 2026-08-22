<?php

namespace App\Http\Resources;

use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Support\OwnerVerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Merges in computed owner-identity/business-verification status for
     * business_owner and manager accounts (null for every other role) so
     * the frontend's gating logic never needs an extra round trip beyond
     * the /auth/me or /auth/login response it already fetches. Also merges
     * in has_active_subscription for business_owner only (null for every
     * other role, including manager — billing stays owner-only, same as
     * the /business/subscription and /business/plans endpoints).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['owner_identity_status'] = null;
        $data['business_verification_status'] = null;
        $data['overall_verification_status'] = null;
        $data['has_active_subscription'] = null;
        $data['staff_name'] = null;
        $data['branch_name'] = null;

        // The login is a User account, but for manager/front_officer roles
        // the "who is this" the dashboard should show is the linked Staff
        // (employee) record, not the User's own name — see User::staff()
        // and SpaBusinessRepository::branchesForUser for why Staff is the
        // source of truth for name/branch on these roles.
        if (in_array($this->role, ['manager', 'front_officer'], true) && $this->resource->staff) {
            $staff = $this->resource->staff;
            $data['staff_name'] = trim("{$staff->first_name} {$staff->last_name}") ?: null;
            $data['branch_name'] = $staff->branch?->branch_name;
        }

        if (in_array($this->role, ['business_owner', 'manager'], true)) {
            $business = app(SpaBusinessRepository::class)->findForUser($this->resource);
            $owner = $this->role === 'business_owner' ? $this->resource : $business?->owner;

            $identityStatus = $owner?->ownerIdentityVerification?->status ?? 'Unregistered';
            $businessStatus = $business?->verification_status ?? 'Unregistered';

            $data['owner_identity_status'] = $identityStatus;
            $data['business_verification_status'] = $businessStatus;
            $data['overall_verification_status'] = OwnerVerificationStatus::compute($identityStatus, $businessStatus);

            if ($this->role === 'business_owner') {
                $data['has_active_subscription'] = $business
                    ? (bool) app(SubscriptionRepository::class)->findActiveForBusiness($business->id)
                    : false;
            }
        }

        return $data;
    }
}
