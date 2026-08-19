<?php

namespace App\Http\Resources;

use App\Services\DocumentUploadService;
use App\Support\OwnerVerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Combines a SpaBusiness (the "Business Ownership" half — nullable, since a
// fresh account has none until its Business Details sub-step creates one),
// its owner's OwnerIdentityVerification (the "Owner Identity" half), and the
// owner User (name/uuid/email) into the single per-account shape the spec
// calls for — used both by the owner's own GET /business/owner/verification
// and the admin review list/drawer. Constructed as new OwnerVerificationResource(
// (object) ['business' => $business, 'identity' => $identity, 'owner' => $user]),
// never directly from an Eloquent model, since no single model holds all three.
class OwnerVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $business = $this->business;
        $identity = $this->identity;
        $owner = $this->owner;

        return [
            'business' => $business ? [
                'uuid' => $business->uuid,
                'business_name' => $business->business_name,
                'business_email' => $business->business_email,
                'business_phone' => $business->business_phone,
                'business_description' => $business->business_description,
                'business_type' => $business->business_type,
                'registration_document_type' => $business->registration_document_type,
                'registered_business_name' => $business->registered_business_name,
                'registered_owner_name' => $business->registered_owner_name,
                'authorized_representative_name' => $business->authorized_representative_name,
                'registration_number' => $business->registration_number,
                'registration_document_url' => app(DocumentUploadService::class)->signedUrl($business->registration_document_path),
                'verification_status' => $business->verification_status,
                'rejection_reason' => $business->verification_status === 'Rejected' ? $business->rejection_reason : null,
                'verified_at' => $business->verified_at,
                'updated_at' => $business->updated_at,
            ] : [
                'uuid' => null,
                'business_name' => null,
                'business_email' => null,
                'business_phone' => null,
                'business_description' => null,
                'business_type' => null,
                'registration_document_type' => null,
                'registered_business_name' => null,
                'registered_owner_name' => null,
                'authorized_representative_name' => null,
                'registration_number' => null,
                'registration_document_url' => null,
                'verification_status' => 'Unregistered',
                'rejection_reason' => null,
                'verified_at' => null,
                'updated_at' => null,
            ],
            'identity' => new OwnerIdentityVerificationResource($identity),
            'owner' => $owner ? [
                'uuid' => $owner->uuid,
                'name' => trim("{$owner->first_name} {$owner->last_name}"),
                'email' => $owner->email,
            ] : null,
            'overall_status' => OwnerVerificationStatus::compute($identity->status, $business->verification_status ?? 'Unregistered'),
        ];
    }
}
