<?php

namespace App\Service\Business;

use App\Exceptions\ActionRefused;
use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Repository\AuditLogRepository;
use App\Repository\Business\ClientRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\LivePulse;
use App\Support\Visits\FrontDeskPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientBookingPauseService
{
    public function __construct(
        private ClientRepository $clients,
        private SpaBusinessRepository $businesses,
        private AuditLogRepository $audit,
    ) {}

    public function pauses(Request $request, string $uuid): array
    {
        $actor = $request->user();
        $business = $this->businesses->findForUser($actor) ?? throw new ActionRefused('No spa business found for this account.');
        $branches = $this->businesses->branchesForUser($actor)->where('spa_business_id', $business->id);
        $client = $this->clients->forBusiness($business->id, $actor->role === 'business_owner' ? null : $branches->pluck('id')->all())
            ->where('uuid', $uuid)->firstOrFail();
        $paused = [];

        if ($client->usesApp()) {
            foreach ($branches as $branch) {
                $policy = BookingPolicy::for($branch);
                if ($policy['no_show_policy'] !== 'suspend') {
                    continue;
                }
                $missed = Appointment::where('client_id', $client->id)->missedSinceLastVisit($branch->id)->count();
                if (FrontDeskPolicy::clientSuspended($policy, $missed)) {
                    $paused[] = ['uuid' => $branch->uuid, 'name' => $branch->branch_name, 'missed' => $missed];
                }
            }
        }

        return ['branches' => $paused, 'can_unpause' => Gate::forUser($actor)->allows('override-booking-rules')];
    }

    public function unpause(Request $request, string $uuid, string $branchUuid, string $reason): void
    {
        $actor = $request->user();
        Gate::forUser($actor)->authorize('override-booking-rules');
        $business = $this->businesses->findForUser($actor) ?? throw new ActionRefused('No spa business found for this account.');
        $branches = $this->businesses->branchesForUser($actor)->where('spa_business_id', $business->id);
        $branch = $branches->firstWhere('uuid', $branchUuid);
        abort_unless($branch instanceof SpaBranch, 404);

        DB::transaction(function () use ($request, $actor, $business, $branches, $branch, $uuid, $reason) {
            $client = $this->clients->forBusiness($business->id, $actor->role === 'business_owner' ? null : $branches->pluck('id')->all())
                ->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $missed = Appointment::where('client_id', $client->id)->missedSinceLastVisit($branch->id);
            $policy = BookingPolicy::for($branch);
            throw_unless($client->usesApp() && FrontDeskPolicy::clientSuspended($policy, (clone $missed)->count()),
                ActionRefused::class, 'App booking is already available at this branch.');

            // Snapshot the misses this reset covers, including ones from other
            // branches of the business. Future no-shows have their own IDs.
            $reset = DB::table('booking_no_show_waivers')->insertUsing(
                ['spa_branch_id', 'appointment_id'],
                $missed->selectRaw('? as booking_branch_id, appointments.id', [$branch->id])->toBase(),
            );

            $this->audit->record($actor->id, 'clients', $client->id, 'Update', null, [
                'client' => trim("{$client->first_name} {$client->last_name}"),
                'branch' => $branch->branch_name,
                'no_shows_reset' => $reset,
                'reason' => $reason,
            ], $request);
            DB::afterCommit(fn () => LivePulse::bump($business->id, 'clients', 'visits'));
        }, 3);
    }
}
