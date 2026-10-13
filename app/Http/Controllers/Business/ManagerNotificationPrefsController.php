<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ManagerNotificationPrefs;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

// A manager's own notification switches (Branch Settings > Notifications):
// which of the owner's changes at their branch they are told about. The
// kinds the owner has locked on come back as `locked`: they read as on and a
// change to them is ignored.
class ManagerNotificationPrefsController extends Controller
{
    public function show(Request $request)
    {
        return $this->answer($request->user());
    }

    public function update(Request $request)
    {
        $changes = $request->validate(array_fill_keys(array_keys(ManagerNotificationPrefs::DEFAULTS), 'sometimes|boolean'));

        $user = $request->user();
        // The manager's own choices are kept as they made them, including
        // for a kind that is locked right now — it applies again if the
        // owner unlocks it. Only a locked kind can't be changed here.
        $locked = $this->locked($user);
        $changes = array_diff_key($changes, array_flip($locked));
        $prefs = array_merge(ManagerNotificationPrefs::for($user), array_map('boolval', $changes));

        try {
            $user->forceFill(['notification_prefs' => $prefs])->save();
        } catch (QueryException $e) {
            report($e);

            return response()->json(['message' => 'Notification settings are not set up on this server yet.'], 503);
        }

        return $this->answer($user, 'Notification settings saved.');
    }

    private function answer(User $user, ?string $message = null)
    {
        $locked = $this->locked($user);

        return response()->json(array_filter([
            'message' => $message,
            'data' => ManagerNotificationPrefs::for($user, $locked),
            'locked' => $locked,
        ], fn ($value) => $value !== null));
    }

    /** @return string[] */
    private function locked(User $user): array
    {
        return ManagerNotificationPrefs::lockedFor($user->staff?->branch?->spa_business_id);
    }
}
