<?php

namespace App\Http\Resources;

use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
use App\Support\SocialLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaBranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Built out explicitly (this used to be a blank parent::toArray()
     * passthrough) so the branch photo and permit document, both stored as
     * Cloudinary public_ids, are exposed as usable URLs instead of raw ids —
     * cover_photo_url is a public CDN link (ImageUploadService::url,
     * display-only), permit_document_url is a signed, authenticated-delivery
     * link (DocumentUploadService::signedUrl, verification evidence only —
     * see the "Branch Photo" vs "Business Permit" distinction in the spec).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'spa_business_id' => $this->spa_business_id,

            'branch_name' => $this->branch_name,
            'code' => $this->code,
            'email' => $this->email,
            'email_verified' => \App\Support\ContactEmail::isVerified($this->resource),
            'phone_number' => $this->phone_number,
            'facebook_url' => $this->facebook_url,
            'instagram_handle' => $this->instagram_handle,
            'website_url' => $this->website_url,
            // The links shown for this branch: its own, else the company's.
            'social_links' => (object) SocialLinks::forBranch($this->resource),
            'description' => $this->description,

            'formatted_address' => $this->formatted_address,

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            'cover_photo_url' => $this->resource->coverPhotoUrl(),

            'permit_number' => $this->permit_number,
            'permit_business_name' => $this->permit_business_name,
            'permit_branch_location' => $this->permit_branch_location,
            'permit_issue_date' => $this->permit_issue_date,
            'permit_confirmed' => (bool) $this->permit_confirmed,
            'permit_document_url' => app(DocumentUploadService::class)->signedUrl($this->permit_document_path),

            'verification_status' => $this->verification_status,
            'operating_status' => $this->operating_status,
            // Switched off by a smaller plan (PlanFitService), not by the
            // owner: it can only come back when the plan has room.
            'on_hold' => (bool) $this->plan_hold_at,

            // Branch Settings: marketplace listing on/off, and the booking
            // rules this branch sets instead of the business defaults.
            'listing_visible' => (bool) $this->listing_visible,
            'booking_overrides' => (object) ($this->booking_overrides ?? []),

            'closure_note' => $this->closure_note,
            'reopens_at' => $this->reopens_at,
            // The temporary closure running now, or the next one coming up
            // (only on the lists that load it).
            'closure' => $this->relationLoaded('closures') ? \App\Support\BranchClosures::notice($this->closures) : null,

            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,

            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->suspension_reason,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'business' => $this->whenLoaded('business', function () {
                return [
                    'uuid' => $this->business->uuid,
                    'business_name' => $this->business->business_name,
                    'owner' => $this->business->relationLoaded('owner') && $this->business->owner ? [
                        'first_name' => $this->business->owner->first_name,
                        'last_name' => $this->business->owner->last_name,
                        'email' => $this->business->owner->email,
                        'phone_number' => $this->business->owner->phone_number,
                    ] : null,
                ];
            }),

            'schedules' => $this->whenLoaded('schedules'),

            // The branch list's card numbers — only present when the query
            // counted them (SpaBranchRepository::paginateForBranches).
            'staff_count' => $this->whenCounted('staff'),
            'rooms_count' => $this->whenCounted('facilities'),
            'manager_name' => $this->when(
                array_key_exists('manager_name', $this->resource->getAttributes()),
                fn () => $this->resource->getAttribute('manager_name'),
            ),
            // How it is doing — the same list query (paginateForBranches).
            'performance' => $this->when(
                array_key_exists('month_visits', $this->resource->getAttributes()),
                fn () => [
                    'month_visits' => (int) $this->resource->getAttribute('month_visits'),
                    'month_completed' => (int) $this->resource->getAttribute('month_completed'),
                    'month_revenue' => (float) $this->resource->getAttribute('month_revenue'),
                    'month_clients' => (int) $this->resource->getAttribute('month_clients'),
                    'last_month_revenue' => (float) $this->resource->getAttribute('last_month_revenue'),
                    'today_visits' => (int) $this->resource->getAttribute('today_visits'),
                ],
            ),

            // Full detail-page relations — only present when the caller
            // eager-loads them (see BranchRepository::findByUuid); the
            // list-page repository doesn't load these, so they simply omit
            // from that response instead of adding N+1 queries there.
            'manager' => $this->whenLoaded('manager', function () {
                return $this->manager ? [
                    'uuid' => $this->manager->uuid,
                    'first_name' => $this->manager->first_name,
                    'last_name' => $this->manager->last_name,
                    'email' => $this->manager->email,
                    'phone_number' => $this->manager->phone_number,
                ] : null;
            }),

            'assigned_account' => $this->whenLoaded('assignedAccount', function () {
                $user = $this->assignedAccount;
                return $user ? [
                    'uuid' => $user->uuid,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                ] : null;
            }),

            'staff' => $this->whenLoaded('staff', fn () => StaffResource::collection($this->staff)),

            'facilities' => $this->whenLoaded('facilities', fn () => FacilityResource::collection($this->facilities)),

            'services' => $this->whenLoaded('branchServices', function () {
                return $this->branchServices->map(fn ($bs) => [
                    'service_variant_uuid' => $bs->serviceVariant?->uuid,
                    // The service this length belongs to, so a list can show
                    // one service with its lengths side by side.
                    'service_uuid' => $bs->serviceVariant?->service?->uuid,
                    'service_name' => $bs->serviceVariant?->service?->name,
                    'category' => $bs->serviceVariant?->service?->category,
                    'service_type' => \App\Support\ServiceKind::of($bs->serviceVariant?->service?->service_type),
                    'duration_minutes' => $bs->serviceVariant?->duration_minutes,
                    'price' => $bs->custom_price !== null
                        ? (float) $bs->custom_price
                        : ($bs->serviceVariant ? (float) $bs->serviceVariant->price : null),
                    'is_available' => (bool) $bs->is_available,
                ]);
            }),

            'packages' => $this->whenLoaded('branchPackages', function () {
                return $this->branchPackages->map(fn ($bp) => [
                    'package_uuid' => $bp->package?->uuid,
                    'package_name' => $bp->package?->name,
                    'price' => $bp->custom_price !== null
                        ? (float) $bp->custom_price
                        : ($bp->package ? (float) $bp->package->default_price : null),
                    'is_available' => (bool) $bp->is_available,
                ]);
            }),
        ];
    }
}
