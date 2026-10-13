<?php

namespace App\Service\Business;

use App\Http\Requests\Business\BranchSettings\BranchBookingPolicyRequest;
use App\Http\Resources\SpaBranchResource;
use App\Models\Review;
use App\Models\SpaBranch;
use App\Models\SpaBranchPhoto;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Services\ImageUploadService;
use App\Support\AppCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// Backs the owner's Branch Settings pages (web /business/settings/branch/*)
// beyond the core branch record: marketplace listing, photo gallery, booking
// overrides and reviews summary. Every lookup is scoped to
// the owner's own business, so another business's branch uuid 404s.
class BranchSettingsService
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private SpaBranchRepository $spaBranchRepository,
        private ImageUploadService $imageUploadService,
        private ManagerChangeNotifier $managerChanges,
    ) {}

    // ── Marketplace listing ──────────────────────────────────────────────

    public function marketplace(User $user, string $uuid)
    {
        $branch = $this->branchOf($user, $uuid);

        return $branch ? $this->marketplacePayload($branch) : $this->noBusiness();
    }

    public function updateMarketplace(User $user, string $uuid, array $fields)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $payload = [];
        if (array_key_exists('listing_visible', $fields)) {
            $payload['listing_visible'] = (bool) $fields['listing_visible'];
        }
        if (array_key_exists('promo_text', $fields)) {
            $payload['promo_text'] = $fields['promo_text'] !== null ? trim($fields['promo_text']) ?: null : null;
        }
        if (array_key_exists('highlights', $fields)) {
            $payload['highlights'] = array_values(array_unique(array_filter(array_map('trim', $fields['highlights']))));
        }
        if (array_key_exists('display', $fields)) {
            $display = array_merge($branch->displaySettings(), $fields['display']);
            foreach ($display as $key => $value) {
                if (is_bool(SpaBranch::DISPLAY_DEFAULTS[$key] ?? null)) {
                    $display[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
            }
            $payload['display_settings'] = array_intersect_key($display, SpaBranch::DISPLAY_DEFAULTS);
        }

        // What is really different, in words, for an owner who asked to hear
        // when a manager changes the listing. Worked out before the save.
        $changed = [];
        if (array_key_exists('listing_visible', $payload) && $payload['listing_visible'] !== (bool) $branch->listing_visible) {
            $changed[] = $payload['listing_visible'] ? 'its visibility (now shown on the marketplace)' : 'its visibility (now hidden from the marketplace)';
        }
        if (array_key_exists('promo_text', $payload) && $payload['promo_text'] !== $branch->promo_text) {
            $changed[] = 'the promo banner';
        }
        if (array_key_exists('highlights', $payload) && $payload['highlights'] != ($branch->highlights ?? [])) {
            $changed[] = 'the highlights';
        }
        if (array_key_exists('display_settings', $payload) && $payload['display_settings'] != array_intersect_key($branch->displaySettings(), SpaBranch::DISPLAY_DEFAULTS)) {
            $changed[] = 'what the listing shows';
        }

        $branch->update($payload);

        if ($changed) {
            $this->managerChanges->tell(
                $user, $branch, ManagerChangeNotifier::LISTING, 'Listing Changed',
                "changed the listing of {$branch->branch_name}: " . implode(', ', $changed) . '.',
            );
        }

        return $this->marketplacePayload($branch->fresh());
    }

    // ── Photo gallery ────────────────────────────────────────────────────

    /** @param UploadedFile[] $files */
    public function uploadPhotos(User $user, string $uuid, array $files)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $this->adoptBranchPhoto($branch);

        $existing = $branch->photos()->count();
        if ($existing + count($files) > SpaBranchPhoto::MAX_PER_BRANCH) {
            $left = max(0, SpaBranchPhoto::MAX_PER_BRANCH - $existing);

            return response()->json([
                'message' => "A branch can have up to ".SpaBranchPhoto::MAX_PER_BRANCH." photos — you can add {$left} more.",
            ], 422);
        }

        $order = (int) $branch->photos()->max('sort_order');
        $hasCover = $branch->photos()->where('is_cover', true)->exists();

        foreach ($files as $file) {
            $path = $this->imageUploadService->store($file, "branches/{$branch->business->uuid}/gallery");
            $branch->photos()->create([
                'path' => $path,
                'sort_order' => ++$order,
                'is_cover' => ! $hasCover,
            ]);
            $hasCover = true;
        }

        $this->photosChanged($user, $branch);

        return $this->marketplacePayload($branch->fresh());
    }

    public function deletePhoto(User $user, string $uuid, string $photoUuid)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $photo = $branch->photos()->where('uuid', $photoUuid)->firstOrFail();
        $wasCover = $photo->is_cover;

        $this->imageUploadService->delete($photo->path);
        $photo->delete();

        // That was the photo from the branch's registration: forget it there
        // too, or the gallery would take it in again (see adoptBranchPhoto).
        if ($branch->cover_photo === $photo->path) {
            $branch->update(['cover_photo' => null]);
        }

        if ($wasCover) {
            $branch->photos()->first()?->update(['is_cover' => true]);
        }

        $this->photosChanged($user, $branch);

        return $this->marketplacePayload($branch->fresh());
    }

    public function setCover(User $user, string $uuid, string $photoUuid)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $photo = $branch->photos()->where('uuid', $photoUuid)->firstOrFail();

        DB::transaction(function () use ($branch, $photo) {
            $branch->photos()->where('is_cover', true)->update(['is_cover' => false]);
            $photo->update(['is_cover' => true]);
        });

        $this->photosChanged($user, $branch);

        return $this->marketplacePayload($branch->fresh());
    }

    // The gallery in the order the owner arranged it — $photoUuids is every
    // photo of the branch, first to last. Clients see them in this order.
    public function reorderPhotos(User $user, string $uuid, array $photoUuids)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $ids = $branch->photos()->pluck('id', 'uuid');
        if ($ids->count() !== count($photoUuids) || collect($photoUuids)->diff($ids->keys())->isNotEmpty()) {
            return response()->json([
                'message' => 'The gallery changed since this page loaded. Reload it and try again.',
            ], 422);
        }

        // One statement for the whole gallery (the ids and positions are
        // integers from here, not from the request). A query-builder update
        // fires no model events, so the cache is cleared by hand.
        $cases = '';
        foreach (array_values($photoUuids) as $position => $photoUuid) {
            $cases .= sprintf(' WHEN %d THEN %d', $ids[$photoUuid], $position + 1);
        }
        SpaBranchPhoto::whereIn('id', $ids->values())->update([
            'sort_order' => DB::raw("CASE id{$cases} END"),
        ]);
        AppCache::bumpBusiness($branch->spa_business_id);

        $this->photosChanged($user, $branch);

        return $this->marketplacePayload($branch->fresh());
    }

    // ── Booking overrides ────────────────────────────────────────────────

    public function updateBookingPolicy(User $user, string $uuid, array $overrides)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $defaults = SpaBusinessSetting::DEFAULTS['booking_defaults'];
        $clean = [];
        foreach (BranchBookingPolicyRequest::KEYS as $key) {
            if (! array_key_exists($key, $overrides)) {
                continue;
            }
            $value = $overrides[$key];
            $clean[$key] = match (true) {
                is_bool($defaults[$key]) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_int($defaults[$key]) && is_numeric($value) => (int) $value,
                default => $value,
            };
        }

        $branch->update(['booking_overrides' => $clean ?: null]);

        $this->managerChanges->tell(
            $user, $branch, ManagerChangeNotifier::BOOKING_RULES, 'Booking Rules Changed',
            "changed the booking rules of {$branch->branch_name}.",
        );

        return new SpaBranchResource($branch->fresh());
    }

    // ── Reviews summary ──────────────────────────────────────────────────

    public function reviewsSummary(User $user, string $uuid)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        $query = Review::query()
            ->where('status', 'Published')
            ->whereHas('appointment', fn ($q) => $q->where('spa_branch_id', $branch->id));

        $counts = (clone $query)->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $counts->sum();
        $average = $count ? round($counts->map(fn ($n, $rating) => $n * $rating)->sum() / $count, 1) : 0;

        $reviews = (clone $query)->with('client')->latest('reviewed_at')->limit(20)->get()->map(fn (Review $r) => [
            'uuid' => $r->uuid,
            'client_name' => $r->is_anonymous || ! $r->client
                ? 'Anonymous'
                : trim("{$r->client->first_name} {$r->client->last_name}"),
            'rating' => $r->rating,
            'comment' => $r->comment,
            'reviewed_at' => $r->reviewed_at?->toDateString(),
        ]);

        return response()->json(['data' => [
            'average' => $average,
            'count' => $count,
            'breakdown' => collect([5, 4, 3, 2, 1])->map(fn ($stars) => ['stars' => $stars, 'count' => (int) ($counts[$stars] ?? 0)])->all(),
            'reviews' => $reviews,
        ]]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    // The owner reaches any of their branches; a manager only their own —
    // any other uuid 404s (see findByUuidForBranches).
    // Uploading, removing, reordering and picking a cover are separate saves;
    // the owner gets one notice for a run of them.
    private function photosChanged(User $user, SpaBranch $branch): void
    {
        $this->managerChanges->tell(
            $user, $branch, ManagerChangeNotifier::LISTING, 'Listing Photos Changed',
            "changed the photos of {$branch->branch_name}.",
            quietMinutes: 10,
        );
    }

    private function branchOf(User $user, string $uuid): ?SpaBranch
    {
        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);

        return $branchIds ? $this->spaBranchRepository->findByUuidForBranches($uuid, $branchIds) : null;
    }

    // The "Branch Photo" uploaded when the branch was registered lives on the
    // branch itself (spa_branches.cover_photo), not in the gallery — so the
    // gallery showed nothing and the preview said "No cover photo yet" for a
    // branch that has one. Take it into the gallery once, as the cover unless
    // the owner already picked one. After that it is an ordinary gallery
    // photo: it can be un-covered, or removed (deletePhoto clears the column).
    private function adoptBranchPhoto(SpaBranch $branch): void
    {
        if (! $branch->cover_photo) {
            return;
        }

        // Loaded onto the branch, so marketplacePayload reuses it.
        $photos = $branch->photos;
        if ($photos->contains('path', $branch->cover_photo) || $photos->count() >= SpaBranchPhoto::MAX_PER_BRANCH) {
            return;
        }

        $branch->photos()->create([
            'path' => $branch->cover_photo,
            // Ahead of anything already in the gallery.
            'sort_order' => min(0, (int) $photos->min('sort_order')) - 1,
            'is_cover' => ! $photos->contains('is_cover', true),
        ]);
        $branch->unsetRelation('photos');
    }

    private function marketplacePayload(SpaBranch $branch)
    {
        $this->adoptBranchPhoto($branch);

        return response()->json(['data' => [
            'listing_visible' => (bool) $branch->listing_visible,
            'promo_text' => $branch->promo_text,
            'highlights' => $branch->highlights ?? [],
            'display' => $branch->displaySettings(),
            'photos' => $branch->photos->map(fn (SpaBranchPhoto $p) => [
                'uuid' => $p->uuid,
                'url' => ImageUploadService::url($p->path),
                'is_cover' => $p->is_cover,
            ]),
            // Whether clients can find and book this branch, and what's missing.
            'readiness' => MarketplaceReadiness::check($branch),
        ]]);
    }

    private function noBusiness()
    {
        return response()->json(['message' => 'No spa business found for this account.'], 422);
    }
}
