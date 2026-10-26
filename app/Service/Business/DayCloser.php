<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\StaleVisit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Closes what a day left open, once the day has ended:
//  - a booking still "booked" (the client never came and nobody marked it)
//    becomes a no-show, with everything a no-show does at the front desk;
//  - a visit still in service with nothing done about it has what was started
//    finished at its estimated end, and what was never started taken off, so
//    it can be billed (AppointmentService::closeStaleVisit);
//  - a visit still checked in, the client having come but nothing started, is
//    closed as not served: cancelled, so its staff, room and voucher are freed
//    (one where something did run is closed like one left in service);
//  - a voucher held for a visit that was never billed (done, but no bill was
//    made once the day ended) is given back to the client: a voucher is only
//    used when a bill is made with it. A cancelled or no-show booking never
//    kept it, and a refunded bill returns it (ProgramRewardService::onBillReopened).
// "A day has ended" is App\Support\StaleVisit (a little after midnight, so a
// service running past it is not cut off). Nothing from the business day in
// progress is touched, and a failure on one visit never stops the rest.
//
// Called from two places, like ServiceEndNotifier, so it does not depend on
// either alone:
//  - every ten minutes by the scheduler (appointments:close-past-days);
//  - whenever a front desk or manager's bell asks for news
//    (Business\NotificationController::index), for their own branches, at most
//    once in five minutes — which is what makes it work where no scheduler runs.
class DayCloser
{
    // Visits closed in one pass: the rest are picked up by the next.
    private const BATCH = 100;

    public function __construct(
        private AppointmentService $appointments,
        private SpaBusinessRepository $spaBusinessRepository,
    ) {
    }

    /**
     * For the branches this person works at; cheap when called again soon
     * (unless $force: the desk asked for it). Never lets a failure reach a
     * caller that did not ask. Null when it did not run.
     *
     * @return array{no_shows: int, finished: int, released: int}|null
     */
    public function sweepForUser(User $user, bool $force = false): ?array
    {
        if (! in_array($user->role, ['front_officer', 'manager', 'business_owner'], true)) {
            return null;
        }

        try {
            $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);
            if (! $branchIds) {
                return null;
            }
            sort($branchIds);
            if (! Cache::add('day-closer:' . md5(implode(',', $branchIds)), 1, 300) && ! $force) {
                return null;
            }

            return $this->sweep($branchIds);
        } catch (\Throwable $e) {
            if ($force) {
                throw $e;
            }
            report($e);

            return null;
        }
    }

    /** The same count for the branches this person works at. */
    public function openCountForUser(User $user): int
    {
        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);

        return $branchIds ? $this->openCount($branchIds) : 0;
    }

    /** How many visits from days that are over are still booked, checked in or in service, for these branches. */
    public function openCount(array $branchIds): int
    {
        return $this->staleQuery(Appointment::query(), $branchIds, [Appointment::STATUS_SCHEDULED])->count()
            + $this->staleQuery(Appointment::query(), $branchIds, [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE], unfinished: true)->count();
    }

    // Visits from days that are over. $unfinished: only the ones that still have
    // a service waiting or running — one with every service done is just waiting
    // to be billed, which is not for this to close.
    private function staleQuery($query, ?array $branchIds, array $statuses, bool $unfinished = false)
    {
        return $query->whereIn('status', $statuses)
            ->where('appointment_date', '<', StaleVisit::today())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->when($unfinished, fn ($q) => $q->whereHas('services', fn ($s) => $s->whereIn('status', ['Pending', 'In Progress'])));
    }

    /**
     * @param  int[]|null  $branchIds  null: every branch
     * @return array{no_shows: int, finished: int, released: int, vouchers: int}
     */
    public function sweep(?array $branchIds = null): array
    {
        $noShows = 0;
        $finished = 0;
        $released = 0;
        $vouchers = $this->returnUnbilledVouchers($branchIds);

        // Staff still timed in when their branch opened again: out at that opening, in for the new day (AttendanceRollover).
        try {
            app(AttendanceRollover::class)->forBranches($branchIds ?? \App\Models\SpaBranch::pluck('id')->all());
        } catch (\Throwable $e) {
            report($e);
        }

        $this->staleQuery(Appointment::query(), $branchIds, [Appointment::STATUS_SCHEDULED])
            ->with('branch.business.settings', 'client', 'queue')
            ->orderBy('id')->limit(self::BATCH)->get()
            ->each(function (Appointment $appointment) use (&$noShows) {
                try {
                    $this->appointments->recordNoShow($appointment, automatic: true);
                    $noShows++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $this->staleQuery(Appointment::query(), $branchIds, [Appointment::STATUS_IN_SERVICE], unfinished: true)
            ->with('queue')
            ->orderBy('id')->limit(self::BATCH)->get()
            ->each(function (Appointment $appointment) use (&$finished) {
                try {
                    $this->appointments->closeStaleVisit($appointment);
                    $finished++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $this->staleQuery(Appointment::query(), $branchIds, [Appointment::STATUS_CHECKED_IN], unfinished: true)
            ->with('queue')
            ->orderBy('id')->limit(self::BATCH)->get()
            ->each(function (Appointment $appointment) use (&$released, &$finished) {
                try {
                    // Something was done before it stalled: closed like a visit left in
                    // service (what ran is finished, what never started is taken off).
                    if ($appointment->services()->whereIn('status', ['In Progress', 'Completed'])->exists()) {
                        $this->appointments->closeStaleVisit($appointment);
                        $finished++;

                        return;
                    }
                    // Came, but was never served: cancelled (nothing was started, so it can be).
                    $refusal = DB::transaction(fn () => $this->appointments->performCancel($appointment, 'Not served: closed automatically at the end of the day.'));
                    if ($refusal === null) {
                        $released++;
                        app(ClientNotifier::class)->appointment(
                            $appointment,
                            'Booking closed',
                            "Your booking {$appointment->appointment_number} at {$appointment->branch?->branch_name} was closed at the end of the day because the visit didn't go ahead. If this is a mistake, please contact the spa.",
                        );
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return ['no_shows' => $noShows, 'finished' => $finished, 'released' => $released, 'vouchers' => $vouchers];
    }

    /**
     * A visit from a day that is over that was made with a wallet voucher but
     * never billed (it is done, or only waiting to be billed): the voucher is
     * freed and the client told. Visits still open for the desk to close, a
     * booking that was never kept, and a cancelled one are not this — those
     * never hold it (ClientVoucher::heldIds).
     *
     * @param  int[]|null  $branchIds
     */
    public function returnUnbilledVouchers(?array $branchIds = null): int
    {
        if (! Appointment::holdsVouchers()) {
            return 0;
        }

        $returned = 0;
        Appointment::query()
            ->whereNotNull('client_voucher_id')
            ->whereIn('status', [Appointment::STATUS_COMPLETED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])
            ->where('appointment_date', '<', StaleVisit::today())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->whereDoesntHave('billing')
            // Nothing left for the desk to close: that is what still keeps it.
            ->whereDoesntHave('services', fn ($q) => $q->whereIn('status', ['Pending', 'In Progress']))
            ->with('voucher.program', 'branch')
            ->orderBy('id')->limit(self::BATCH)->get()
            ->each(function (Appointment $appointment) use (&$returned) {
                try {
                    $name = $appointment->voucher?->dealProgram()?->name;
                    $appointment->update(['client_voucher_id' => null]);
                    $returned++;
                    // A rewards notice: tapping it opens that spa's rewards.
                    if ($appointment->client) {
                        app(ProgramRewardService::class)->notifyClient(
                            $appointment->client,
                            'Your voucher has been returned',
                            "Your booking {$appointment->appointment_number} at {$appointment->branch?->branch_name} wasn't billed, so your " . ($name ? "“{$name}” voucher" : 'voucher') . ' is back in your wallet.',
                        );
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $returned;
    }
}
