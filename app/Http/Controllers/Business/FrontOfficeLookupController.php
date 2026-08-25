<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\FrontOfficeLookupService;
use Illuminate\Http\Request;

class FrontOfficeLookupController extends Controller
{
    private FrontOfficeLookupService $frontOfficeLookupService;

    public function __construct(FrontOfficeLookupService $frontOfficeLookupService)
    {
        $this->frontOfficeLookupService = $frontOfficeLookupService;
    }

    public function therapists(Request $request)
    {
        return $this->frontOfficeLookupService->therapists($request->user());
    }

    public function facilities(Request $request)
    {
        return $this->frontOfficeLookupService->facilities($request->user());
    }

    public function services(Request $request)
    {
        return $this->frontOfficeLookupService->services($request->user());
    }
}
