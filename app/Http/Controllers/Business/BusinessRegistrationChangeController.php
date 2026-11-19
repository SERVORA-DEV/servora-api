<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\RegistrationChangeRequest;
use App\Service\Business\BusinessRegistrationChangeService;
use Illuminate\Http\Request;

// The owner's own request to change the company registration of a verified
// business (BusinessRegistrationChangeService).
class BusinessRegistrationChangeController extends Controller
{
    public function __construct(private BusinessRegistrationChangeService $changes) {}

    public function show(Request $request)
    {
        return $this->changes->show($request->user());
    }

    public function submit(RegistrationChangeRequest $request)
    {
        $data = $request->validated();

        return $this->changes->submit($request->user(), $data, $request->file('registration_document'), $request);
    }

    public function cancel(Request $request)
    {
        return $this->changes->cancel($request->user(), $request);
    }
}
