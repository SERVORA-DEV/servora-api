<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Backs the unauthenticated GET /business/{uuid}/public endpoint — the
// branded /login/{business_uuid} page fetches this before anyone has
// signed in, so only ever expose fields safe to show to a stranger with
// the uuid (name + logo for branding). No email/phone/description/
// verification internals — use SpaBusinessResource for the owner's own,
// authenticated view of those.
class PublicSpaBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            // business_logo is a Cloudinary public_id, not something a
            // client can render. business_logo_url is the resolved CDN
            // URL; the raw id stays for now so existing web/mobile
            // consumers of this key don't break.
            'business_logo' => $this->business_logo,
            'business_logo_url' => ImageUploadService::url($this->business_logo),
        ];
    }
}
