<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientChangePasswordRequest;
use App\Service\Client\ClientAccountService;
use Illuminate\Http\Request;

class ClientAccountController extends Controller
{
    public function __construct(private ClientAccountService $account)
    {
    }

    public function favorites(Request $request)
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $lat = $request->query('lat');
        $lng = $request->query('lng');

        return $this->account->favorites(
            $request->user(),
            $lat !== null ? (float) $lat : null,
            $lng !== null ? (float) $lng : null,
        );
    }

    public function addFavorite(Request $request, string $branchUuid)
    {
        return $this->account->addFavorite($request->user(), $branchUuid);
    }

    public function removeFavorite(Request $request, string $branchUuid)
    {
        return $this->account->removeFavorite($request->user(), $branchUuid);
    }

    public function transactions(Request $request)
    {
        return $this->account->transactions($request->user());
    }

    public function notifications(Request $request)
    {
        return $this->account->notifications($request->user());
    }

    public function markNotificationRead(Request $request, int $id)
    {
        return $this->account->markNotificationRead($request->user(), $id);
    }

    public function markAllNotificationsRead(Request $request)
    {
        return $this->account->markAllNotificationsRead($request->user());
    }

    public function changePassword(ClientChangePasswordRequest $request)
    {
        return $this->account->changePassword($request->user(), $request->validated());
    }
}
