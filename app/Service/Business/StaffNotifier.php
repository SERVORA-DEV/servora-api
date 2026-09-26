<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Repository\NotificationRepository;
use Illuminate\Support\Facades\DB;

// Puts a notification in the feed of the people who run a branch — its
// owner, its manager and (for booking events) its front-desk accounts.
// Sibling of ClientNotifier and follows the same rules: written after the
// surrounding transaction commits (dropped if it rolls back) and never fails
// the caller, since a feed entry is a courtesy, not part of the change itself.
//
// Honors the owner's Settings > Notifications (the in-app channel switch, and
// the per-event toggle when one is named) unless the notice is $critical —
// a suspension is not something an owner can opt out of hearing about.
class StaffNotifier
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    public function appointment(
        Appointment $appointment,
        string $title,
        string $message,
        ?string $preference = null,
        string $type = 'Appointment',
        bool $includeFrontDesk = true,
        ?int $exceptUserId = null,
    ): void {
        $appointment->loadMissing('branch.business');
        if (! $appointment->branch) {
            return;
        }

        $this->branch(
            $appointment->branch,
            $title,
            $message,
            type: $type,
            roles: $includeFrontDesk ? ['manager', 'frontdesk'] : ['manager'],
            preference: $preference,
            exceptUserId: $exceptUserId,
            reference: $appointment->uuid,
        );
    }

    /** @param  string[]  $roles  Staff job roles to include: 'manager', 'frontdesk'. */
    public function branch(
        SpaBranch $branch,
        string $title,
        string $message,
        string $type = 'System',
        array $roles = ['manager', 'frontdesk'],
        bool $includeOwner = true,
        ?string $preference = null,
        ?int $exceptUserId = null,
        ?string $reference = null,
        bool $critical = false,
    ): void {
        $branch->loadMissing('business.settings');
        $business = $branch->business;
        if (! $business) {
            return;
        }

        if (! $critical) {
            $settings = $business->settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];
            if (! $settings['in_app_channel'] || ($preference !== null && ! ($settings[$preference] ?? true))) {
                return;
            }
        }

        $recipients = Staff::query()
            ->where('spa_branch_id', $branch->id)
            ->where('status', 'active')
            ->whereIn('role', $roles)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->when($includeOwner, fn ($ids) => $ids->push($business->owner_id))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $exceptUserId)
            ->values()
            ->all();

        if (! $recipients) {
            return;
        }

        DB::afterCommit(function () use ($recipients, $title, $message, $type, $reference) {
            foreach ($recipients as $userId) {
                try {
                    $this->notifications->create($userId, $title, $message, $type, $reference);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
