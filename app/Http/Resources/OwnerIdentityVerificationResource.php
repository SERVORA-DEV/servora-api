<?php

namespace App\Http\Resources;

use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Never exposes id_document_front_path/id_document_back_path/face_scan_paths
// (Cloudinary public_ids) directly — only signed, authenticated-delivery
// Cloudinary URLs (see DocumentUploadService::signedUrl), so the private
// files are never reachable except through that signature.
class OwnerIdentityVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $documentUploadService = app(DocumentUploadService::class);

        $faceScanUrls = [];
        foreach ($this->face_scan_paths ?? [] as $path) {
            $faceScanUrls[] = $documentUploadService->signedUrl($path);
        }

        return [
            'uuid' => $this->uuid,
            'id_type' => $this->id_type,
            'id_document_front_url' => $documentUploadService->signedUrl($this->id_document_front_path),
            'id_document_back_url' => $documentUploadService->signedUrl($this->id_document_back_path),
            'face_scan_urls' => $faceScanUrls,
            'liveness_sequence' => $this->liveness_sequence,
            'liveness_result' => $this->liveness_result,
            'status' => $this->status,
            'rejected_field' => $this->status === 'Rejected' ? $this->rejected_field : null,
            'rejection_reason' => $this->status === 'Rejected' ? $this->rejection_reason : null,
            'verified_at' => $this->verified_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
