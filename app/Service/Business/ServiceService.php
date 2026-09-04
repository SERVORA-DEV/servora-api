<?php

namespace App\Service\Business;

use App\Models\BranchService;
use App\Models\User;
use App\Repository\Business\BranchServiceRepository;
use App\Repository\Business\ServiceRepository;
use App\Repository\Business\ServiceVariantRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\ServiceVariantResource;
use App\Services\ImageUploadService;

class ServiceService
{
    private ServiceRepository $serviceRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private SpaBranchRepository $spaBranchRepository;
    private BranchServiceRepository $branchServiceRepository;
    private ServiceVariantRepository $serviceVariantRepository;
    private ImageUploadService $imageUploadService;

    public function __construct(
        ServiceRepository $serviceRepository,
        SpaBusinessRepository $spaBusinessRepository,
        SpaBranchRepository $spaBranchRepository,
        BranchServiceRepository $branchServiceRepository,
        ServiceVariantRepository $serviceVariantRepository,
        ImageUploadService $imageUploadService,
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->branchServiceRepository = $branchServiceRepository;
        $this->serviceVariantRepository = $serviceVariantRepository;
        $this->imageUploadService = $imageUploadService;
    }

    // business_owner's branch ids cover the whole business; manager's cover
    // only their own staff record's branch — see
    // SpaBusinessRepository::branchesForUser. Same convention as
    // FacilityService::branchIds().
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    public function listServices(User $user, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->serviceRepository->paginateForBusiness($business->id, $this->branchIds($user), $perPage);
        return ServiceResource::collection($collection);
    }

    /**
     * New variants start with no BranchService rows at all — the owner is
     * prompted right after create (see the "Assign Branches" panel on the
     * frontend) to explicitly choose which branches offer it, rather than
     * every branch being silently auto-enabled. Branch assignment is saved
     * separately via updateVariantBranches().
     */
    public function createService(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $variants = $payload['variants'] ?? [];
        unset($payload['variants']);

        if (! empty($payload['image'])) {
            $payload['image_path'] = $this->imageUploadService->store($payload['image'], 'services');
        }
        unset($payload['image']);

        $payload['spa_business_id'] = $business->id;
        $payload['created_by'] = $user->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $service = $this->serviceRepository->create($payload);
        $this->serviceVariantRepository->syncForService($service->id, $variants);

        return new ServiceResource($service->load('variants'));
    }

    public function getService(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $model = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id, $this->branchIds($user));
        return new ServiceResource($model);
    }

    public function updateService(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't (or isn't a service of) this business —
        // update($uuid, ...) alone wouldn't scope that check.
        $service = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id);

        $variants = $payload['variants'] ?? null;
        unset($payload['variants']);

        // Only touch image_path when a new file was actually uploaded — an
        // absent 'image' key leaves the column untouched by the partial
        // update() below, so the existing image is kept as-is.
        if (! empty($payload['image'])) {
            $payload['image_path'] = $this->imageUploadService->replace($service->image_path, $payload['image'], 'services');
        }
        unset($payload['image']);

        $model = $this->serviceRepository->update($uuid, $payload);

        if ($variants !== null) {
            $syncedVariants = $this->serviceVariantRepository->syncForService($service->id, $variants);

            // firstOrCreate is a no-op for a variant that already has branch
            // rows — this only fills in rows for brand-new variants, it
            // never touches an existing variant's custom_price/is_available.
            foreach ($this->branchIds($user) as $branchId) {
                foreach ($syncedVariants as $variant) {
                    BranchService::firstOrCreate(
                        ['spa_branch_id' => $branchId, 'service_variant_id' => $variant->id],
                        ['is_available' => true]
                    );
                }
            }
        }

        return new ServiceResource($model->load('variants'));
    }

    public function deleteService(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $service = $this->serviceRepository->findByUuidForBusiness($uuid, $business->id);

        // Deleted immediately rather than kept for a soft-delete restore —
        // there's no restore endpoint for services today, so keeping the
        // file around would only ever leave it orphaned.
        $this->imageUploadService->delete($service->image_path);

        $this->serviceRepository->delete($uuid);
        return true;
    }

    // Bulk-replaces one variant's branch_services rows — one per submitted
    // branch — rather than a per-row endpoint, since branch_services has no
    // uuid of its own to address and the frontend always edits the full set
    // at once (same "replace wholesale" shape as
    // PackageService::syncPackageServices). Scoped to a single variant, not
    // the whole service, since price/availability now live per variant.
    public function updateVariantBranches(User $user, string $variantUuid, array $branches)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);
        $variant = $this->serviceVariantRepository->findByUuidForBusiness($variantUuid, $business->id);

        $rows = [];
        foreach ($branches as $branch) {
            // 404s if this uuid isn't one of the caller's own accessible
            // branches — a manager can't smuggle in a row for a branch they
            // don't own even if they send one, same guard
            // FacilityService::createFacility uses for spa_branch_uuid.
            $spaBranch = $this->spaBranchRepository->findByUuidForBranches($branch['branch_uuid'], $branchIds);

            $rows[] = [
                'spa_branch_id' => $spaBranch->id,
                'is_available' => $branch['is_available'],
                'custom_price' => $branch['custom_price'] ?? null,
                'custom_commission' => $branch['custom_commission'] ?? null,
            ];
        }

        $this->branchServiceRepository->syncForVariant($variant->id, $rows);

        $variant = $this->serviceVariantRepository->findByUuidForBusiness($variantUuid, $business->id, $branchIds);
        return new ServiceVariantResource($variant);
    }
}
