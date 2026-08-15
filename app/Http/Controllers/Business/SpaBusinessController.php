<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\SpaBusinessService;
use Illuminate\Http\Request;

class SpaBusinessController extends Controller
{
    private SpaBusinessService $spaBusinessService;

    public function __construct(SpaBusinessService $spaBusinessService)
    {
        $this->spaBusinessService = $spaBusinessService;
    }

    public function me(Request $request)
    {
        return $this->spaBusinessService->getMyBusiness($request->user());
    }

    // Unauthenticated — see SpaBusinessService::getPublicBusiness /
    // PublicSpaBusinessResource for what's safe to expose here.
    public function publicShow(string $uuid)
    {
        return $this->spaBusinessService->getPublicBusiness($uuid);
    }
}
