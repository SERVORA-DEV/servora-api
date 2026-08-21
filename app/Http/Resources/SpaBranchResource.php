<?php

namespace App\Http\Resources;

use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
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
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'description' => $this->description,

            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'formatted_address' => $this->formatted_address,

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            'cover_photo_url' => ImageUploadService::url($this->cover_photo),

            'permit_number' => $this->permit_number,
            'permit_business_name' => $this->permit_business_name,
            'permit_branch_location' => $this->permit_branch_location,
            'permit_issue_date' => $this->permit_issue_date,
            'permit_expiration_date' => $this->permit_expiration_date,
            'permit_confirmed' => (bool) $this->permit_confirmed,
            'permit_document_url' => app(DocumentUploadService::class)->signedUrl($this->permit_document_path),

            'verification_status' => $this->verification_status,
            'operating_status' => $this->operating_status,

            'closure_note' => $this->closure_note,
            'reopens_at' => $this->reopens_at,

            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,

            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->suspension_reason,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'business' => $this->whenLoaded('business', function () {
                return [
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
        ];
    }
}
