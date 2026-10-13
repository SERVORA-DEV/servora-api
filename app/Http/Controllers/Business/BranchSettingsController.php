<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchSettings\BranchBookingPolicyRequest;
use App\Http\Requests\Business\BranchSettings\BranchMarketplaceRequest;
use App\Http\Requests\Business\BranchSettings\BranchPhotoOrderRequest;
use App\Http\Requests\Business\BranchSettings\BranchPhotoUploadRequest;
use App\Service\Business\BranchSettingsService;
use Illuminate\Http\Request;

class BranchSettingsController extends Controller
{
    public function __construct(private BranchSettingsService $branchSettingsService) {}

    public function marketplace(Request $request, string $uuid)
    {
        return $this->branchSettingsService->marketplace($request->user(), $uuid);
    }

    public function updateMarketplace(BranchMarketplaceRequest $request, string $uuid)
    {
        return $this->branchSettingsService->updateMarketplace($request->user(), $uuid, $request->validated());
    }

    public function uploadPhotos(BranchPhotoUploadRequest $request, string $uuid)
    {
        return $this->branchSettingsService->uploadPhotos($request->user(), $uuid, $request->file('photos', []));
    }

    public function deletePhoto(Request $request, string $uuid, string $photo)
    {
        return $this->branchSettingsService->deletePhoto($request->user(), $uuid, $photo);
    }

    public function setCover(Request $request, string $uuid, string $photo)
    {
        return $this->branchSettingsService->setCover($request->user(), $uuid, $photo);
    }

    public function reorderPhotos(BranchPhotoOrderRequest $request, string $uuid)
    {
        return $this->branchSettingsService->reorderPhotos($request->user(), $uuid, $request->validated()['photos']);
    }

    public function updateBookingPolicy(BranchBookingPolicyRequest $request, string $uuid)
    {
        return $this->branchSettingsService->updateBookingPolicy($request->user(), $uuid, $request->validated()['overrides'] ?? []);
    }

    public function reviewsSummary(Request $request, string $uuid)
    {
        return $this->branchSettingsService->reviewsSummary($request->user(), $uuid);
    }

}
