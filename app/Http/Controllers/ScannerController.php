<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Service\Business\AppointmentService;
use App\Service\Business\HomeVisitDoorService;
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

    // ── The visit at the door, step by step (HomeVisitDoorService) ──────
    // Each takes the visit's code, the same as a scan, and answers with the visit as it stands.
    // (start() above is the old one-step scan, kept for pages still open on it.)

    private function code(Request $request): string
    {
        $code = HomeVisitStart::read($request->input('code'));
        abort_if($code === null, 422, 'That isn\'t a Servora home-service code. Scan the QR on the client\'s booking, or type the code under it.');

        return $code;
    }

    public function look(Request $request, string $token, HomeVisitDoorService $door)
    {
        return response()->json(['data' => $door->look($this->branch($token), $this->code($request))]);
    }

    public function startVisit(Request $request, string $token, HomeVisitDoorService $door)
    {
        return response()->json(['data' => $door->start($this->branch($token), $this->code($request))]);
    }

    public function extendOptions(Request $request, string $token, HomeVisitDoorService $door)
    {
        $branch = $this->branch($token);
        $code = $this->code($request);
        $data = $request->validate(['service_uuid' => ['required', 'string', 'max:64']]);

        return response()->json(['data' => $door->extendOptions($branch, $code, $data['service_uuid'])]);
    }

    public function extend(Request $request, string $token, HomeVisitDoorService $door)
    {
        $branch = $this->branch($token);
        $code = $this->code($request);
        $data = $request->validate([
            'service_uuid' => ['required', 'string', 'max:64'],
            'extra_minutes' => ['required_without:service_variant_uuid', 'nullable', 'integer', 'min:1', 'max:600'],
            'service_variant_uuid' => ['required_without:extra_minutes', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $door->extend($branch, $code, $data)]);
    }

    public function complete(Request $request, string $token, HomeVisitDoorService $door)
    {
        return response()->json(['data' => $door->complete($this->branch($token), $this->code($request))]);
    }

    public function checkout(Request $request, string $token, HomeVisitDoorService $door)
    {
        $branch = $this->branch($token);
        $code = $this->code($request);
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'in:'.implode(',', HomeVisitDoorService::DOOR_METHODS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ], [
            'payment_method.in' => 'At the client\'s home, take cash, GCash or Maya. A card payment is taken at the spa.',
        ]);

        return response()->json(['data' => $door->checkout($branch, $code, $data)]);
    }
}
