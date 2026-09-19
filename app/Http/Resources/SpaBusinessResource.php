<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Owner-authenticated view of their own business — see PublicSpaBusinessResource
// for the unauthenticated/branded-login-page-safe subset of this.
class SpaBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            // business_logo is a Cloudinary public_id, not something a
            // client can render. business_logo_url is the resolved CDN
            // URL; the raw id stays for now so existing web/mobile
            // consumers of this key don't break.
            'business_logo' => $this->business_logo,
            'business_logo_url' => ImageUploadService::url($this->business_logo),
            'business_description' => $this->business_description,
            'verification_status' => $this->verification_status,
            'operating_status' => $this->operating_status,
        ];
    }
}
