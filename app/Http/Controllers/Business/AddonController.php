<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AddonRequest;
use App\Service\Business\AddonService;
use Illuminate\Http\Request;

// The owner's add-ons (the Add-ons tab beside Services and Packages). Reading
// is open to managers too; creating, editing and removing are the owner's —
// see routes/api.php.
class AddonController extends Controller
{
    public function __construct(private AddonService $addonService)
    {
    }

    public function index(Request $request)
    {
        return $this->addonService->listAddons($request->user());
    }

    public function store(AddonRequest $request)
    {
        return $this->addonService->createAddon($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->addonService->getAddon($request->user(), $uuid);
    }

    public function update(AddonRequest $request, string $uuid)
    {
        return $this->addonService->updateAddon($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->addonService->deleteAddon($request->user(), $uuid);
    }
}
