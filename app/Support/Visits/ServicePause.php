<?php

namespace App\Support\Visits;

use App\Enums\AssignmentStatus;
use App\Models\AppointmentServiceItem;
use App\Models\TherapistAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * A service in progress can be paused (the client steps out): its clock stops while the
 * therapist and the room stay held. While it is paused the front desk may give it to another
 * therapist (VisitAssignmentService::changeTherapist). Then it is resumed.
 *
 * The status stays In Progress; "paused" is appointment_services.paused_at being set.
 *
 * How the clock stops: on resume, the start of everyone still working on it is moved later by
 * the time it was paused. Everything that works out "started + length" (the service-end
 * notice, who is busy until when, the end-of-day close, the desk's timer) is then right without
 * knowing about pauses. paused_minutes keeps the total, to show.
 */
class ServicePause
{
    /** Whether the pause columns exist yet (migration 2026_01_01_000830). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:service_pause');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointment_services', 'paused_at');
        $ready ? Cache::forever('schema:service_pause', true) : Cache::put('schema:service_pause', false, 60);

        return $ready;
    }

    public static function isPaused(AppointmentServiceItem $line): bool
    {
        return self::ready() && $line->paused_at !== null;
    }

    /** Minutes of the pause going on now (0 when it isn't paused). */
    public static function currentMinutes(AppointmentServiceItem $line, ?Carbon $now = null): int
    {
        if (! self::isPaused($line)) {
            return 0;
        }

        return (int) max(0, round(Carbon::parse($line->paused_at)->diffInSeconds($now ?? now(), true) / 60));
    }

    /**
     * Ends the pause of one line (and of the add-ons riding with it): the clock goes on.
     * Everyone working on it now starts later by the time it was paused.
     */
    public static function end(AppointmentServiceItem $line, ?Carbon $now = null): void
    {
        if (! self::isPaused($line)) {
            return;
        }
        $now ??= now();
        $seconds = (int) Carbon::parse($line->paused_at)->diffInSeconds($now, true);

        $ids = collect([$line->id])->merge($line->addons()->pluck('id'))->all();
        TherapistAssignment::whereIn('appointment_service_id', $ids)
            ->where('assignment_status', AssignmentStatus::InProgress->value)
            ->whereNotNull('started_at')
            ->get()
            ->each(fn (TherapistAssignment $a) => $a->update(['started_at' => Carbon::parse($a->started_at)->addSeconds($seconds)]));

        $line->update([
            'paused_at' => null,
            'paused_minutes' => (int) $line->paused_minutes + (int) round($seconds / 60),
        ]);
    }
}
