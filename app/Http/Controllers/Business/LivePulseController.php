<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Repository\SpaBusinessRepository;
use App\Support\LivePulse;
use Illuminate\Http\Request;

class LivePulseController extends Controller
{
    // The one cheap thing an open screen asks every few seconds: has anything
    // changed at this business since I last looked, and in which areas?
    // (App\Support\LivePulse) `token` changes with any of them; `topics` has
    // one token per area, so a screen reads again only for what it shows.
    public function show(Request $request, SpaBusinessRepository $businesses)
    {
        $business = $businesses->findForUser($request->user());

        return response()->json([
            'token' => $business ? LivePulse::token($business->id) : null,
            'topics' => $business ? LivePulse::topics($business->id) : null,
        ]);
    }
}
