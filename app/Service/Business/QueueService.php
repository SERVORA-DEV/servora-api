<?php

namespace App\Service\Business;

use App\Http\Resources\QueueResource;
use App\Models\User;
use App\Repository\Business\QueueRepository;
use App\Repository\SpaBusinessRepository;

// Read-only — every queue state change is a side effect of an
// AppointmentService lifecycle action (check-in queues automatically,
// then startService, completeService, cancelAppointment update it), kept
// there so there's exactly one write path per appointment. This class only
// serves the Queue tab's list view.
class QueueService
{
    private QueueRepository $queueRepository;
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(QueueRepository $queueRepository, SpaBusinessRepository $spaBusinessRepository)
    {
        $this->queueRepository = $queueRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    public function listForBranchToday(User $user, array $filters)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
        $date = $filters['date'] ?? now()->format('Y-m-d');

        $collection = $this->queueRepository->listForBranchDate($branchIds, $date, $filters['status'] ?? null);

        return QueueResource::collection($collection);
    }
}
