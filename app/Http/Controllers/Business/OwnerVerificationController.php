<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OwnerBusinessDetailsRequest;
use App\Http\Requests\Business\OwnerBusinessVerificationRequest;
use App\Http\Requests\Business\OwnerFaceScanRequest;
use App\Http\Requests\Business\OwnerIdentityDocumentRequest;
use App\Http\Requests\Business\OwnerPersonalDetailsRequest;
use App\Service\Business\OwnerVerificationService;
use Illuminate\Http\Request;

class OwnerVerificationController extends Controller
{
    public function __construct(private OwnerVerificationService $ownerVerificationService) {}

    public function show(Request $request)
    {
        return $this->ownerVerificationService->getStatus($request->user());
    }

    public function savePersonalDetails(OwnerPersonalDetailsRequest $request)
    {
        return $this->ownerVerificationService->savePersonalDetails($request->user(), $request->validated());
    }

    public function saveBusinessDetails(OwnerBusinessDetailsRequest $request)
    {
        return $this->ownerVerificationService->saveBusinessDetails($request->user(), $request->validated());
    }

    public function saveIdentity(OwnerIdentityDocumentRequest $request)
    {
        return $this->ownerVerificationService->saveIdentityDocument(
            $request->user(),
            $request->validated(),
            $request->file('id_document_front'),
            $request->file('id_document_back'),
            $request
        );
    }

    public function saveFaceScan(OwnerFaceScanRequest $request)
    {
        return $this->ownerVerificationService->saveFaceScan(
            $request->user(),
            $request->validated(),
            $request->file('frames'),
            $request
        );
    }

    public function saveBusiness(OwnerBusinessVerificationRequest $request)
    {
        return $this->ownerVerificationService->saveBusinessVerification(
            $request->user(),
            $request->validated(),
            $request->file('registration_document'),
            $request
        );
    }

    public function submit(Request $request)
    {
        return $this->ownerVerificationService->submit($request->user(), $request);
    }
}
