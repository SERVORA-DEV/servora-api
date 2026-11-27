<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebActivityController
{
    public function __invoke(Request $request): Response
    {
        $now = now();

        User::query()
            ->whereKey($request->user()->id)
            ->where('account_status', AccountStatus::Active->value)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('last_web_activity_at')
                ->orWhere('last_web_activity_at', '<=', $now->copy()->subMinutes(15))
                ->orWhereNotNull('inactivity_reminder_sent_at'))
            ->toBase()
            ->update(['last_web_activity_at' => $now, 'inactivity_reminder_sent_at' => null]);

        return response()->noContent();
    }
}
