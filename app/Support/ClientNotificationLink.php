<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Notification;
use App\Models\SpaBusiness;

// What tapping a notification in the client app opens, sent with the
// notification (ClientNotificationResource, and the phone push's data):
//   - kind 'queue_call': the front desk called the client — the app opens the
//     queue screen for that booking (reference_uuid is the appointment);
//   - kind 'reward': points, vouchers, a membership — the app opens that spa's
//     rewards (business_uuid).
// No extra column: the call is told by its title, and a rewards notice refers
// to the business (ProgramRewardService::notifyClient). Older rewards notices
// referred to the visit's appointment instead, so that is looked up too.
class ClientNotificationLink
{
    public const QUEUE_CALL_TITLE = 'The spa is ready for you';

    // Earlier wording of the same call, so notifications already sent still open the queue.
    private const OLD_QUEUE_CALL_TITLES = ['Your appointment is ready for you'];

    public static function kind(Notification $notification): ?string
    {
        return match (true) {
            $notification->type === 'Appointment'
                && in_array($notification->title, [self::QUEUE_CALL_TITLE, ...self::OLD_QUEUE_CALL_TITLES], true) => 'queue_call',
            $notification->type === 'Reward' => 'reward',
            default => null,
        };
    }

    /**
     * The business each rewards notice is about, by notification id — two
     * queries at most, for a whole page.
     *
     * @param  iterable<Notification>  $notifications
     * @return array<int, string>
     */
    public static function businessUuids(iterable $notifications): array
    {
        $rewards = collect($notifications)->filter(fn (Notification $n) => $n->type === 'Reward' && $n->reference_uuid);
        if ($rewards->isEmpty()) {
            return [];
        }
        $references = $rewards->pluck('reference_uuid')->unique()->values();

        $businesses = SpaBusiness::whereIn('uuid', $references)->pluck('uuid', 'uuid');
        $left = $references->reject(fn ($uuid) => $businesses->has($uuid))->values();
        $byAppointment = $left->isEmpty() ? collect() : Appointment::query()
            ->join('spa_branches', 'spa_branches.id', '=', 'appointments.spa_branch_id')
            ->join('spa_businesses', 'spa_businesses.id', '=', 'spa_branches.spa_business_id')
            ->whereIn('appointments.uuid', $left)
            // Both tables call it `uuid`: named apart, or one hides the other.
            ->select('appointments.uuid as appointment_uuid', 'spa_businesses.uuid as business_uuid')
            ->pluck('business_uuid', 'appointment_uuid');

        return $rewards
            ->mapWithKeys(fn (Notification $n) => [$n->id => $businesses->get($n->reference_uuid) ?? $byAppointment->get($n->reference_uuid)])
            ->filter()
            ->all();
    }

    /** One notification's business, for a push sent on its own. */
    public static function businessUuid(Notification $notification): ?string
    {
        return self::businessUuids([$notification])[$notification->id] ?? null;
    }
}
