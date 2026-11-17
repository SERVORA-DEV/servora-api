<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OwnerFaceScanRequest;
use App\Http\Requests\Business\OwnerIdentityDocumentRequest;
use App\Service\Business\OwnerIdentityChangeService;
use Illuminate\Http\Request;

// The owner's own request to change the name or email on their verified
// account (OwnerIdentityChangeService).
class OwnerIdentityChangeController extends Controller
{
    public function __construct(private OwnerIdentityChangeService $changes) {}

    public function show(Request $request)
    {
        return $this->changes->show($request->user());
    }

    public function details(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:60',
            'middle_name' => 'nullable|string|max:60',
            'last_name' => 'required|string|max:60',
            'suffix' => 'nullable|string|max:10',
            'email' => 'required|email:rfc|max:120',
        ]);

        return $this->changes->saveDetails($request->user(), $data);
    }

    public function resendCode(Request $request)
    {
        return $this->changes->resendCode($request->user());
    }

    public function confirmEmail(Request $request)
    {
        $data = $request->validate(['code' => 'required|digits:6']);

        return $this->changes->confirmEmail($request->user(), $data['code']);
    }

    public function saveId(OwnerIdentityDocumentRequest $request)
    {
        return $this->changes->saveId($request->user(), $request->validated()['id_type'], $request->file('id_document_front'), $request->file('id_document_back'));
    }

    public function saveFaceScan(OwnerFaceScanRequest $request)
    {
        return $this->changes->saveFaceScan($request->user(), $request->file('frames'), $request->validated()['liveness_sequence']);
    }

    public function submit(Request $request)
    {
        return $this->changes->submit($request->user(), $request);
    }

    public function cancel(Request $request)
    {
        return $this->changes->cancel($request->user(), $request);
    }
}
