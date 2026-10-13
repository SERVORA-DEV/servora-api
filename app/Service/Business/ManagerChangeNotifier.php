<?php

namespace App\Service\Business;

use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\User;
use App\Repository\NotificationRepository;
use App\Support\AppCache;
use App\Support\ManagerNotificationPrefs;
use Illuminate\Support\Facades\DB;

// Tells the people who run a branch when someone else changes something
// there.
//
// A MANAGER's change goes to the owner — but only the kinds the owner asked
// to hear about (Business Settings > Notifications > "When a manager changes
// something", all off by default).
//
// The OWNER's change goes to that branch's manager(s), each by their own
// switches (Branch Settings > Notifications, all on by default — see
// ManagerNotificationPrefs). So does a change to the shared catalog
// (tellCatalog), for every branch that offers the item.
//
// EITHER one's change also goes to the branch's FRONT DESK accounts, who
// work by these hours, prices, rooms and rosters. That is the owner's choice
// alone, per kind (Business Settings > Notifications > "Front desk is told
// about", frontdesk_… in the business's settings, all on by default); a
// front desk account has no switches of its own.
//
// Nobody is told about their own change. The services call tell() after a
// successful save; like StaffNotifier it writes after the surrounding
// transaction commits and never fails the caller: a notification is a
// courtesy, not part of the change itself.
//
// ONE NOTIFICATION PER SAVE. One click on a screen is often several requests
// (a room's switch saves by itself, then Save sends the rest; an employee's
// details, services and schedule are three), and each reaches here on its
// own. So a change by someone whose earlier change the reader hasn't read
// yet, from the last couple of minutes, is added to that notification rather
// than becoming another one — and adds nothing when it says the same thing.
// See write().
//
// The titles are what the web app looks for to send a click to the right page
// (see staffNotificationDestination) — keep the two in step.
class ManagerChangeNotifier
{
    // The kinds of change. Each is the owner's switch as written; the
    // manager's switch for the same kind is on_owner_… (see managerKey()).
    public const OFFERINGS = 'on_manager_offerings';
    public const HOURS = 'on_manager_hours';
    public const BOOKING_RULES = 'on_manager_booking_rules';
    public const ROOMS = 'on_manager_rooms';
    public const STAFF = 'on_manager_staff';
    public const LISTING = 'on_manager_listing';

    // Every title this class writes: only these are joined together.
    private const TITLES = [
        'Branch Offerings Changed', 'Catalog Changed',
        'Opening Hours Changed', 'Branch Status Changed', 'Booking Rules Changed',
        'Listing Changed', 'Listing Photos Changed', 'Branch Program Changed',
        'Room Added', 'Room Changed', 'Room Removed',
        'Employee Added', 'Employee Changed', 'Employee Services Changed', 'Employee Schedule Changed', 'Employee Removed',
    ];

    // Changes by one person this close together read as one save.
    private const JOIN_MINUTES = 2;

    // A notification holds this many changes, then just says there were more.
    private const MAX_CHANGES = 5;
    private const MORE = 'And more changes.';

    public function __construct(private NotificationRepository $notifications)
    {
    }

    /**
     * @param  SpaBranch|\Closure():?SpaBranch|null  $branch  A closure is run only when someone might
     *                                                        be told, so a save never pays to load it
     *                                                        for nothing.
     * @param  string|\Closure():string  $what  What they did, to follow their name:
     *                                          "changed the opening hours of Acacia." A closure
     *                                          is only run when the notification will be sent.
     * @param  string|null  $reference  What a click opens: a staff uuid, or (default) the branch.
     * @param  int  $quietMinutes  How long after their last unread change this one still joins
     *                             it instead of becoming a new notification. The default covers
     *                             one save; a screen that saves piece by piece (photos) asks for longer.
     */
    public function tell(
        User $actor,
        SpaBranch|\Closure|null $branch,
        string $preference,
        string $title,
        string|\Closure $what,
        ?string $reference = null,
        int $quietMinutes = 0,
    ): void {
        if (! in_array($actor->role, ['business_owner', 'manager'], true)) {
            return;
        }

        try {
            $branch = $branch instanceof \Closure ? $branch() : $branch;
            if (! $branch) {
                return;
            }

            // The business's master switch first: before anyone is looked up
            // or the message (which may cost a query) is built.
            $settings = $this->settings($branch->spa_business_id);
            if (! ($settings['in_app_channel'] ?? true)) {
                return;
            }

            // The owner's change is for the branch's manager(s); anyone's is
            // for its front desk.
            $userIds = $this->staffToTell(
                [$branch->id],
                $branch->spa_business_id,
                $settings,
                $actor->role === 'business_owner' ? self::managerKey($preference) : null,
                self::frontDeskKey($preference),
            );

            // A manager's change is for the owner too, when the owner asked
            // to hear about this kind.
            if ($actor->role === 'manager' && ($settings[$preference] ?? false)) {
                $userIds[] = $this->ownerOf($branch, $actor);
            }

            $this->write($this->except($userIds, $actor), $title, $actor, $what, $reference ?? $branch->uuid, $quietMinutes);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A manager changed something the owner always hears about, and nobody
     * else does — what a staff member is paid. No switch for it besides the
     * business's in-app one.
     *
     * @param  SpaBranch|\Closure():?SpaBranch|null  $branch
     * @param  string|\Closure():string  $what
     */
    public function tellOwner(User $actor, SpaBranch|\Closure|null $branch, string $title, string|\Closure $what, ?string $reference = null): void
    {
        if ($actor->role !== 'manager') {
            return;
        }

        try {
            $branch = $branch instanceof \Closure ? $branch() : $branch;
            if (! $branch || ! ($this->settings($branch->spa_business_id)['in_app_channel'] ?? true)) {
                return;
            }

            $this->write($this->except([$this->ownerOf($branch, $actor)], $actor), $title, $actor, $what, $reference ?? $branch->uuid);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The owner changed something in the shared catalog (a standard price, a
     * service switched off or removed): tells the manager and the front desk
     * of every branch that offers it.
     *
     * @param  int[]|\Closure():int[]  $branchIds  The branches that offer it.
     * @param  string|\Closure():?string  $what  What changed, to follow the owner's name. Null or
     *                                           empty: nothing a manager needs to hear about.
     */
    public function tellCatalog(User $actor, int $businessId, array|\Closure $branchIds, string|\Closure $what): void
    {
        if ($actor->role !== 'business_owner') {
            return;
        }

        try {
            $settings = $this->settings($businessId);
            if (! ($settings['in_app_channel'] ?? true)) {
                return;
            }
            $what = $what instanceof \Closure ? $what() : $what;
            if (! $what) {
                return;
            }
            $branchIds = $branchIds instanceof \Closure ? $branchIds() : $branchIds;
            $userIds = $this->staffToTell($branchIds, $businessId, $settings, ManagerNotificationPrefs::CATALOG, self::frontDeskKey(self::OFFERINGS));

            $this->write($this->except($userIds, $actor), 'Catalog Changed', $actor, $what, null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // "1h 30min" — a service length in the words the screens use.
    public static function length(?int $minutes): string
    {
        if (! $minutes) {
            return '';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours ? "{$hours}h" : '') . ($rest ? " {$rest}min" : ''));
    }

    // "₱1,150" (centavos only when there are any).
    public static function peso(float|string|null $amount): string
    {
        $amount = (float) $amount;

        return '₱' . number_format($amount, fmod($amount, 1) ? 2 : 0);
    }

    // ── internals ───────────────────────────────────────────────────────

    // on_manager_hours (the owner's switch) → on_owner_hours (a manager's).
    private static function managerKey(string $preference): string
    {
        return str_replace('on_manager_', 'on_owner_', $preference);
    }

    // on_manager_hours (the owner's switch) → frontdesk_hours (the owner's
    // switch for whether the front desk hears about that kind).
    private static function frontDeskKey(string $preference): string
    {
        return str_replace('on_manager_', 'frontdesk_', $preference);
    }

    // The business's notification switches (Business Settings > Notifications).
    private function settings(int $businessId): array
    {
        return AppCache::businessSettings($businessId)?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];
    }

    /**
     * The staff accounts at these branches to tell, in one query:
     *  - the managers who want $managerKey (theirs to choose, unless the owner
     *    locked that kind on) — null: managers are not told;
     *  - every front desk account, when the owner has $frontDeskKey on.
     *
     * @param  int[]  $branchIds
     * @return int[]  user ids
     */
    private function staffToTell(array $branchIds, int $businessId, array $settings, ?string $managerKey, string $frontDeskKey): array
    {
        $roles = array_merge(
            $managerKey ? ['manager'] : [],
            ($settings[$frontDeskKey] ?? true) ? ['frontdesk'] : [],
        );
        if (! $branchIds || ! $roles) {
            return [];
        }

        // A kind the owner has locked on reaches every manager, whatever
        // their own switch says.
        $locked = $managerKey && in_array($managerKey, ManagerNotificationPrefs::lockedFor($businessId), true);

        return Staff::with('user')
            ->whereIn('spa_branch_id', $branchIds)
            ->where('status', 'active')
            ->whereIn('role', $roles)
            ->whereNotNull('user_id')
            ->get()
            ->filter(fn (Staff $staff) => $staff->user
                && ($staff->role !== 'manager' || $locked || ManagerNotificationPrefs::wants($staff->user, $managerKey)))
            ->pluck('user_id')
            ->all();
    }

    // The owner of the branch's business.
    private function ownerOf(SpaBranch $branch, User $manager): ?int
    {
        // The manager's own business is usually loaded on their account
        // already (it is how every service finds "their" business) — reuse
        // it rather than asking the database for it again.
        $own = $manager->staff?->branch;
        if ($own && $own->spa_business_id === $branch->spa_business_id && ! $branch->relationLoaded('business')) {
            $branch->setRelation('business', $own->business);
        }
        $branch->loadMissing('business');

        return $branch->business?->owner_id;
    }

    /**
     * Each person once, and never the one who made the change.
     *
     * @param  array<int|null>  $userIds
     * @return int[]
     */
    private function except(array $userIds, User $actor): array
    {
        return array_values(array_unique(array_filter($userIds, fn ($id) => $id && $id !== $actor->id)));
    }

    /**
     * Tells each of $userIds that $actor did $what — as a new notification,
     * or as part of the one they already have from $actor and haven't read.
     *
     * @param  string|\Closure():string  $what  "changed the opening hours of Acacia."
     */
    private function write(array $userIds, string $title, User $actor, string|\Closure $what, ?string $reference, int $joinMinutes = 0): void
    {
        if (! $userIds) {
            return;
        }

        $name = $this->nameOf($actor);
        $what = trim($what instanceof \Closure ? $what() : $what);
        $minutes = max(self::JOIN_MINUTES, $joinMinutes);

        DB::afterCommit(function () use ($userIds, $title, $name, $what, $reference, $minutes) {
            foreach ($userIds as $userId) {
                try {
                    $earlier = $this->notifications->recentUnreadFrom($userId, self::TITLES, "{$name} ", $minutes);
                    if (! $earlier) {
                        $this->notifications->create($userId, $title, "{$name} {$what}", 'System', $reference);
                        continue;
                    }

                    // It already says this (the same save sent twice), or it
                    // is full: nothing to add.
                    if (str_contains($earlier->message, $what) || str_ends_with($earlier->message, self::MORE)) {
                        continue;
                    }

                    $changes = substr_count($earlier->message, ' Also ') + 1;
                    $earlier->update(['message' => $earlier->message . ' ' . ($changes >= self::MAX_CHANGES ? self::MORE : 'Also ' . $what)]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    private function nameOf(User $user): string
    {
        // Only a staff account has a staff record to take the name from.
        $staffName = $user->role === 'business_owner'
            ? ''
            : trim(($user->staff?->first_name ?? '') . ' ' . ($user->staff?->last_name ?? ''));

        return $staffName ?: (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->role === 'business_owner' ? 'The owner' : 'A manager'));
    }
}
