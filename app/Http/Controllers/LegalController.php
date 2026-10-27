<?php

namespace App\Http\Controllers;

use App\Support\LegalDocuments;
use Illuminate\Support\Facades\Log;

// GET /api/legal — the Terms of Service and Privacy Policy, for the web app's
// /terms and /privacy pages and the mobile app's Terms screen. Public: people
// read these before they have an account. The text lives in config/legal.php.
class LegalController extends Controller
{
    public function show()
    {
        // Readers must never see a [placeholder] in production; say so in the log.
        if (app()->isProduction() && ($unresolved = LegalDocuments::unresolved())) {
            Log::warning('The legal documents still contain placeholders.', ['unresolved' => $unresolved]);
        }

        return response()->json(['data' => LegalDocuments::content()]);
    }
}
