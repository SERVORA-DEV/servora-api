<?php

namespace App\Http\Controllers;

use App\Support\MobileRelease;

// What the mobile app reads when it opens, to know whether to offer (or require) an update.
class MobileReleaseController extends Controller
{
    public function show(MobileRelease $releases)
    {
        return response()->json(['data' => $releases->current()]);
    }
}
