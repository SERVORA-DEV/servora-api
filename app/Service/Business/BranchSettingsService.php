<?php

namespace App\Service\Business;

use App\Http\Requests\Business\BranchSettings\BranchBookingPolicyRequest;
use App\Http\Resources\SpaBranchResource;
use App\Models\Review;
use App\Models\SpaBranch;
use App\Models\SpaBranchPhoto;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\AdminUsersRepository;
use App\Service\NotificationService;
use App\Services\DocumentUploadService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// Backs the owner's Branch Settings pages (web /business/settings/branch/*)
// beyond the core branch record: marketplace listing, photo gallery, booking
// overrides, reviews summary and permit renewal. Every lookup is scoped to
// the owner's own business, so another business's branch uuid 404s.
class BranchSettingsService
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private SpaBranchRepository $spaBranchRepository,
        private ImageUploadService $imageUploadService,
        private DocumentUploadService $documentUploadService,
        private AuditLogRepository $auditLogRepository,
        private AdminUsersRepository $adminUsersRepository,
        private NotificationService $notificationService,
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

        $branch->update($payload);

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

        if ($wasCover) {
            $branch->photos()->first()?->update(['is_cover' => true]);
        }

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

    // ── Permit renewal ───────────────────────────────────────────────────

    // Verified branches renew here; an unverified or rejected branch fixes
    // its permit through the registration wizard (SpaBranchService::
    // savePermit) instead. The branch stays Verified and listed — admins
    // are notified so they can look at the new document.
    public function renewPermit(User $user, string $uuid, array $payload, UploadedFile $file, ?Request $request = null)
    {
        $branch = $this->branchOf($user, $uuid);
        if (! $branch) {
            return $this->noBusiness();
        }

        if ($branch->verification_status !== 'Verified') {
            return response()->json([
                'message' => 'Only a verified branch can renew its permit here. Finish the branch registration instead.',
            ], 422);
        }

        $old = $branch->only(['permit_number', 'permit_issue_date', 'permit_expiration_date']);

        $path = $this->documentUploadService->replace(
            $branch->permit_document_path,
            $file,
            "verification/branch/{$branch->uuid}/permit"
        );

        $branch->update([
            'permit_document_path' => $path,
            'permit_number' => $payload['permit_number'],
            'permit_business_name' => $payload['permit_business_name'],
            'permit_branch_location' => $payload['permit_branch_location'],
            'permit_issue_date' => $payload['permit_issue_date'],
            'permit_expiration_date' => $payload['permit_expiration_date'],
            'permit_confirmed' => true,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'spa_branches',
            $branch->id,
            'Upload',
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $old),
            ['permit_number' => $branch->permit_number, 'permit_expiration_date' => $branch->permit_expiration_date?->toDateString()],
            $request,
        );

        $this->notificationService->branchPermitRenewed($branch, $this->adminUsersRepository->allAdministrators());

        return new SpaBranchResource($branch->fresh());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function branchOf(User $user, string $uuid): ?SpaBranch
    {
        $business = $this->spaBusinessRepository->findByOwnerId($user->id);

        return $business ? $this->spaBranchRepository->findByUuidForBusiness($uuid, $business->id) : null;
    }

    private function marketplacePayload(SpaBranch $branch)
    {
        return response()->json(['data' => [
            'listing_visible' => (bool) $branch->listing_visible,
            'promo_text' => $branch->promo_text,
            'highlights' => $branch->highlights ?? [],
            'display' => $branch->displaySettings(),
            'photos' => $branch->photos()->get()->map(fn (SpaBranchPhoto $p) => [
                'uuid' => $p->uuid,
                'url' => ImageUploadService::url($p->path),
                'is_cover' => $p->is_cover,
            ]),
        ]]);
    }

    private function noBusiness()
    {
        return response()->json(['message' => 'No spa business found for this account.'], 422);
    }
}
