<?php

namespace App\Service\Client;

use App\Http\Resources\Client\ClientNotificationResource;
use App\Http\Resources\Client\ClientTransactionResource;
use App\Http\Resources\NearbySpaResource;
use App\Models\Billing;
use App\Models\ClientFavorite;
use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReviewRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// The rest of a mobile client's own account: saved spas, receipts, their
// notification feed and password. Everything is scoped to the signed-in
// user; nothing here reaches another account's rows.
class ClientAccountService
{
    public function __construct(
        private SpaBranchRepository $branches,
        private ReviewRepository $reviews,
        private NotificationRepository $notifications,
    ) {
    }

    // ── Favorites ────────────────────────────────────────────────────────

    public function favorites(User $user, ?float $lat, ?float $lng)
    {
        $ids = ClientFavorite::where('user_id', $user->id)->orderByDesc('created_at')->pluck('spa_branch_id')->all();
        $order = array_flip($ids);

        $branches = $this->branches->publicByIds($ids)
            ->sortBy(fn (SpaBranch $b) => $order[$b->id] ?? PHP_INT_MAX)
            ->values();

        foreach ($branches as $branch) {
            $branch->setAttribute('distance_km', ($lat !== null && $lng !== null)
                ? self::distanceKm($lat, $lng, (float) $branch->latitude, (float) $branch->longitude)
                : null);
        }

        $this->reviews->attachRatings($branches);

        return NearbySpaResource::collection($branches);
    }

    public function addFavorite(User $user, string $branchUuid)
    {
        $branch = $this->branches->publicFindByUuid($branchUuid);
        ClientFavorite::firstOrCreate(['user_id' => $user->id, 'spa_branch_id' => $branch->id]);

        return response()->json(['data' => ['branch_uuid' => $branch->uuid, 'is_favorite' => true]]);
    }

    // Idempotent, and deliberately not behind the public-branch guard: a
    // client must be able to un-save a spa that has since been unlisted.
    public function removeFavorite(User $user, string $branchUuid)
    {
        $branchId = SpaBranch::where('uuid', $branchUuid)->value('id');
        if ($branchId) {
            ClientFavorite::where('user_id', $user->id)->where('spa_branch_id', $branchId)->delete();
        }

        return response()->json(['data' => ['branch_uuid' => $branchUuid, 'is_favorite' => false]]);
    }

    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ── Transactions ─────────────────────────────────────────────────────

    // A client's receipts are the billings the front desk raised on their
    // appointments (payment happens at the spa — there's no in-app payment).
    public function transactions(User $user)
    {
        $billings = Billing::query()
            ->whereHas('appointment', fn ($q) => $q->whereHas('client', fn ($c) => $c->where('user_id', $user->id)))
            ->with('appointment.branch.business', 'appointment.services.serviceVariant.service', 'payments')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(50);

        return ClientTransactionResource::collection($billings);
    }

    // ── Notifications ────────────────────────────────────────────────────

    public function notifications(User $user)
    {
        return ClientNotificationResource::collection($this->notifications->paginateForUser($user->id, 50))
            ->additional(['meta' => ['unread_count' => $this->notifications->countUnreadForUser($user->id)]]);
    }

    public function markNotificationRead(User $user, int $id)
    {
        if (! $this->notifications->markReadForUser($user->id, $id)) {
            abort(404);
        }

        return response()->json(['data' => ['unread_count' => $this->notifications->countUnreadForUser($user->id)]]);
    }

    public function markAllNotificationsRead(User $user)
    {
        $this->notifications->markAllReadForUser($user->id);

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    // ── Password ─────────────────────────────────────────────────────────

    // Signs out every other device (their tokens are revoked) but keeps the
    // one making the change signed in.
    public function changePassword(User $user, array $payload)
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        if (Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your current one.']);
        }

        $user->forceFill(['password' => Hash::make($payload['password'])])->save();

        $current = $user->currentAccessToken();
        $user->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
