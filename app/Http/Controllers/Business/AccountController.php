<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AccountRequest;
use App\Http\Requests\Business\AccountSuggestionRequest;
use App\Service\Business\AccountService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private AccountService $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    public function index(Request $request)
    {
        return $this->accountService->listAccounts($request->user(), $request->input('per_page', 15));
    }

    public function store(AccountRequest $request)
    {
        return $this->accountService->createAccount($request->user(), $request->validated());
    }

    // Read-only preview of the username/email a generated account would get -
    // see AccountService::suggestCredentials. Registered ahead of this
    // controller's apiResource in routes/api.php so "suggest" isn't swallowed
    // by show()'s {account} wildcard.
    public function suggest(AccountSuggestionRequest $request)
    {
        return $this->accountService->suggestCredentials($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->accountService->getAccount($request->user(), $uuid);
    }

    public function update(AccountRequest $request, string $uuid)
    {
        return $this->accountService->updateAccount($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->accountService->deleteAccount($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
}
