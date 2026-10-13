<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Service\Business\AppointmentService;
use App\Support\HomeVisitStart;
use Illuminate\Http\Request;

// A branch's scanner: the page a member of staff opens on their own phone to
// start a home visit by scanning the client's QR (App\Support\HomeVisitStart).
// Nobody is signed in — the secret in the link says which branch it is, and
// only that branch's bookings can be started through it. The front desk hands
// the link out and can replace it (FrontOfficeLookupService::scannerLink).
class ScannerController extends Controller
{
    private function branch(string $token): SpaBranch
    {
        $branch = Appointment::hasHomeStart() && strlen($token) >= 32
            ? SpaBranch::where('scanner_token', $token)->first()
            : null;
        abort_if(! $branch, 404, 'This scanner link no longer works. Ask the front desk for the new one.');

        return $branch;
    }

    public function show(string $token)
    {
        $branch = $this->branch($token);
        $branch->loadMissing('business');

        return response()->json(['data' => [
            'branch_name' => $branch->branch_name,
            'business_name' => $branch->business?->business_name,
        ]]);
    }

    public function start(Request $request, string $token, AppointmentService $appointments)
    {
        $branch = $this->branch($token);

        $code = HomeVisitStart::read($request->input('code'));
        if ($code === null) {
            return response()->json(['message' => 'That isn\'t a Servora home-service code. Scan the QR on the client\'s booking, or type the code under it.'], 422);
        }

        return response()->json(['data' => $appointments->startHomeVisitByCode($branch, $code)]);
    }
}
