<?php

namespace App\Http\Controllers;

use App\Repository\SpaBusinessRepository;
use App\Support\ContactEmail;
use Illuminate\Http\Request;

// Confirming the business's or a branch's contact email (App\Support\
// ContactEmail): the button in the email lands here, and Settings can send the
// email again.
class ContactEmailController extends Controller
{
    public function __construct(private SpaBusinessRepository $businesses) {}

    // GET (signed) — the button in the email. Back to the site with how it went.
    public function verify(string $kind, string $uuid, string $hash)
    {
        $status = ContactEmail::confirm($kind, $uuid, $hash);

        return redirect(rtrim((string) config('app.frontend_url'), '/') . '/verify-contact?status=' . $status);
    }

    // POST business/settings/identity/email/resend — the business's email.
    public function resendBusiness(Request $request)
    {
        $business = $this->businesses->findForUser($request->user());
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        return $this->resend($business);
    }

    // POST business/branch/{uuid}/email/resend — a branch's email.
    public function resendBranch(Request $request, string $uuid)
    {
        $branch = $this->businesses->branchesForUser($request->user())->firstWhere('uuid', $uuid);
        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        return $this->resend($branch);
    }

    private function resend($subject)
    {
        $email = $subject instanceof \App\Models\SpaBusiness ? $subject->business_email : $subject->email;
        if (! $email) {
            return response()->json(['message' => 'There is no email to confirm yet.'], 422);
        }
        if (ContactEmail::isVerified($subject)) {
            return response()->json(['message' => 'This email is already confirmed.', 'verified' => true]);
        }
        if (! ContactEmail::send($subject)) {
            return response()->json(['message' => 'We could not send the email right now. Please try again in a moment.'], 503);
        }

        return response()->json(['message' => "We sent a button to {$email}. Open it and press Confirm.", 'verified' => false]);
    }
}
