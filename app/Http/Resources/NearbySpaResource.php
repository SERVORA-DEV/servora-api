<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use App\Support\BranchHoursCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Backs the unauthenticated GET /spas/nearby endpoint (client mobile app's
// Home "Near You" and Explore) — same "only what's safe to show a stranger"
// rule as PublicSpaBusinessResource: no email/phone/description/verification
// internals, just what a browse card needs to display and link out to.
//
// The open-now, category and starting-price fields exist for Explore's
// filters. They're derived from the same sources BranchDetailResource uses
// (BranchHoursCalculator, and the public-only services/packages that
// SpaBranchRepository::nearby() eager-loads), so a card and the details
// screen it opens always agree. Every one of them tolerates its relation not
// being loaded, degrading to "open, no categories, no price" rather than
// triggering a lazy load per branch.
class NearbySpaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'branch_name' => $this->branch_name,
            'formatted_address' => $this->formatted_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => $this->distance_km !== null ? round((float) $this->distance_km, 2) : null,
            // Set by ReviewRepository::attachRatings(); null until a branch
            // has its first published review.
            'rating_avg' => $this->rating_avg,
            'rating_count' => (int) ($this->rating_count ?? 0),
            'cover_photo_url' => $this->resource->coverPhotoUrl(),

            ...$this->openStatus(),
            'service_categories' => $this->serviceCategories(),
            'starting_price' => $this->startingPrice(),

            'business' => $this->whenLoaded('business', function () {
                return [
                    'uuid' => $this->business->uuid,
                    'business_name' => $this->business->business_name,
                    // See SpaBusinessResource for why both keys are here.
                    'business_logo' => $this->business->business_logo,
                    'business_logo_url' => ImageUploadService::url($this->business->business_logo),
                ];
            }),
        ];
    }

    /** @return array{is_open_now: bool, opens_at: ?string, closes_at: ?string} */
    private function openStatus(): array
    {
        if (! $this->relationLoaded('schedules')) {
            return ['is_open_now' => true, 'opens_at' => null, 'closes_at' => null];
        }

        $hours = BranchHoursCalculator::resolve($this->schedules, Carbon::now());

        return [
            'is_open_now' => $hours['is_open_now'],
            'opens_at' => $hours['opens_at'],
            'closes_at' => $hours['closes_at'],
        ];
    }

    /** @return list<string> */
    private function serviceCategories(): array
    {
        if (! $this->relationLoaded('branchServices')) {
            return [];
        }

        return $this->branchServices
            ->map(fn ($bs) => $bs->serviceVariant?->service?->category)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // Cheapest bookable price across services and packages — the same rule
    // as the mobile BranchDetail.startingPrice, with a branch's custom_price
    // winning over the business-wide default exactly as BranchDetailResource
    // prices each row.
    private function startingPrice(): ?float
    {
        $prices = collect();

        if ($this->relationLoaded('branchServices')) {
            $prices = $prices->merge($this->branchServices->map(
                fn ($bs) => $bs->custom_price ?? $bs->serviceVariant?->price
            ));
        }

        if ($this->relationLoaded('branchPackages')) {
            $prices = $prices->merge($this->branchPackages->map(
                fn ($bp) => $bp->custom_price ?? $bp->package?->default_price
            ));
        }

        $prices = $prices->filter(fn ($price) => $price !== null);

        return $prices->isEmpty() ? null : (float) $prices->min(fn ($price) => (float) $price);
    }
}
