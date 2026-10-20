<?php

namespace App\Http\Resources;

use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Support\OwnerVerificationStatus;
use App\Support\AdminPermissions;
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
        // The business a manager / front officer works for. Their sign-in
        // page is that business's own (/login/{business_uuid}), so the web
        // app needs it to send them back there on logout.
        $data['business_uuid'] = null;

        // Settings → Security state — computed here rather than left for the
        // frontend to infer from raw timestamps, same reasoning as the
        // verification-status fields below (one place owns the business
        // rule). two_factor_secret/recovery_codes/personal_email_otp_hash
        // stay $hidden on User — never exposed even indirectly.
        $data['two_factor_enabled'] = $this->two_factor_confirmed_at !== null;
        $data['personal_email_verified'] = $this->personal_email_verified_at !== null;

        // What this administrator may do, so the admin panel can hide the
        // areas and buttons the API would refuse (EnsurePermission).
        $data['permissions'] = $this->role === 'system_administrator'
            ? AdminPermissions::effective($this->resource)
            : null;

        // The login is a User account, but for manager/front_officer roles
        // the "who is this" the dashboard should show is the linked Staff
        // (employee) record, not the User's own name — see User::staff()
        // and SpaBusinessRepository::branchesForUser for why Staff is the
        // source of truth for name/branch on these roles.
        //
        // A staff login works at the branches the owner gave it, each at a
        // level (App\Support\AccountScope); the first is where the screens
        // start, and the web switches between them.
        $scope = in_array($this->role, ['manager', 'front_officer'], true)
            ? \App\Support\AccountScope::allBranches($this->resource)
            : collect();
        if ($scope->isNotEmpty()) {
            $levels = \App\Support\AccountScope::levels($this->resource);
            $data['staff_name'] = $this->resource->displayName();
            $data['branches'] = $scope->map(fn ($b) => [
                'uuid' => $b->uuid,
                'name' => $b->branch_name,
                'level' => $levels[$b->id] ?? 'operation',
                'letterhead' => $b->business ? [
                    'business_name' => $b->business->business_name,
                    'legal_name' => $b->business->legal_name,
                    'registration_number' => $b->business->registration_number,
                    'business_website' => $b->business->website_url,
                    'business_logo_url' => \App\Services\ImageUploadService::url($b->business->business_logo),
                    'business_email' => $b->business->business_email,
                    'branch_name' => $b->branch_name,
                    'branch_address' => $b->formatted_address,
                    'branch_phone' => $b->phone_number ?: $b->business->business_phone,
                ] : null,
            ])->values();
            $data['branch_name'] = $scope->first()->branch_name;

            // Letterhead for receipts and printed reports — the owner's
            // settings endpoint that normally supplies this is owner-only.
            $branch = $scope->first();
            $business = $branch?->business;
            $data['business_uuid'] = $business?->uuid;
            $data['letterhead'] = $business ? [
                'business_name' => $business->business_name,
                'legal_name' => $business->legal_name,
                'registration_number' => $business->registration_number,
                'business_website' => $business->website_url,
                'business_logo_url' => \App\Services\ImageUploadService::url($business->business_logo),
                'business_email' => $business->business_email,
                'branch_name' => $branch->branch_name,
                'branch_address' => $branch->formatted_address,
                'branch_phone' => $branch->phone_number ?: $business->business_phone,
            ] : null;
        }

        // What a spa account may do, so the dashboards can hide buttons the
        // API would refuse (EnsurePermission): the role's bundle, narrowed by
        // the account's own user_permissions row for staff accounts.
        if (in_array($this->role, ['business_owner', 'manager', 'front_officer'], true)) {
            $row = $this->role === 'business_owner' ? null : $this->resource->permission;
            $data['permissions'] = collect(config('permission.' . $this->role, []))
                ->mapWithKeys(fn ($key) => [$key => $row ? $row->getAttribute($key) === true : true])
                ->all();
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
