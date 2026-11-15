<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
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

    public function submit(Request $request)
    {
        $data = $request->validate([
            // A DTI certificate number is digits only.
            'registration_number' => ['required', 'string', 'max:100', 'regex:/^\d+$/'],
            'registered_business_name' => 'required|string|max:255',
            'registered_owner_name' => 'required|string|max:255',
            'note' => 'nullable|string|max:500',
            'registration_document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('uploads.document_max_size_kb', 8192)],
        ], [
            'registration_number.regex' => 'A DTI certificate number is digits only.',
            'registration_document.required' => 'Upload the new DTI Business Name Certificate.',
        ]);

        return $this->changes->submit($request->user(), $data, $request->file('registration_document'), $request);
    }

    public function cancel(Request $request)
    {
        return $this->changes->cancel($request->user(), $request);
    }
}
