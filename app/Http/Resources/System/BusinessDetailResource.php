<?php

namespace App\Http\Resources\System;

use App\Http\Resources\OwnerIdentityVerificationResource;
use App\Http\Resources\PackageResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\SpaBranchResource;
use App\Http\Resources\SubscriptionResource;
use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;

// Full "view everything" shape for a single business on the system admin
// detail page — extends the thin list-page BusinessResource with the
// registration/verification documents, the owner's identity verification,
// every branch, subscription/billing history, and the service/package
// catalog. The nested relations only populate when the caller eager-loads
// them (see BusinessRepository::findByUuid) — this resource doesn't trigger
// any queries on its own.
class BusinessDetailResource extends BusinessResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'business_logo_url' => ImageUploadService::url($this->business_logo),
            'business_description' => $this->business_description,
            'business_type' => $this->business_type,

            'registration_document_type' => $this->registration_document_type,
            'registered_business_name' => $this->registered_business_name,
            'registered_owner_name' => $this->registered_owner_name,
            'authorized_representative_name' => $this->authorized_representative_name,
            'registration_number' => $this->registration_number,
            'registration_document_url' => app(DocumentUploadService::class)->signedUrl($this->registration_document_path),

            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,
            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->suspension_reason,

            // Overrides the parent's thin owner block with full contact
            // info plus the owner's identity verification status.
            'owner' => $this->whenLoaded('owner', function () {
                if (! $this->owner) {
                    return null;
                }

                return [
                    'uuid' => $this->owner->uuid,
                    'first_name' => $this->owner->first_name,
                    'last_name' => $this->owner->last_name,
                    'email' => $this->owner->email,
                    'phone_number' => $this->owner->phone_number,
                    'identity_verification' => $this->owner->relationLoaded('ownerIdentityVerification') && $this->owner->ownerIdentityVerification
                        ? new OwnerIdentityVerificationResource($this->owner->ownerIdentityVerification)
                        : null,
                ];
            }),

            'branches' => $this->whenLoaded('branches', fn () => SpaBranchResource::collection($this->branches)),

            'subscriptions' => $this->whenLoaded('subscriptions', fn () => SubscriptionResource::collection($this->subscriptions)),

            'services' => $this->whenLoaded('services', fn () => ServiceResource::collection($this->services)),
            'packages' => $this->whenLoaded('packages', fn () => PackageResource::collection($this->packages)),
        ];
    }
}
