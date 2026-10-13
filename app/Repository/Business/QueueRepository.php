<?php

namespace App\Repository\Business;

use App\Models\Queue;

class QueueRepository
{
    public function create(array $payload): Queue
    {
        return Queue::create($payload);
    }

    // Daily-reset counter per branch — resets each new appointment_date
    // rather than being a global sequence. The owner's Queue number format
    // decides how it reads: 'numeric' → 001, 002…; 'alpha-numeric' → Q-001…
    // The count goes by the digits, so changing the format mid-day carries on.
    public function nextQueueNumber(int $spaBranchId, string $date, string $format = 'alpha-numeric'): string
    {
        $highest = Queue::where('spa_branch_id', $spaBranchId)
            ->where('appointment_date', $date)
            ->pluck('queue_number')
            ->map(fn (string $number) => (int) preg_replace('/\D+/', '', $number))
            ->max();

        $next = str_pad((string) (($highest ?? 0) + 1), 3, '0', STR_PAD_LEFT);

        return $format === 'numeric' ? $next : 'Q-' . $next;
    }

    /** How many clients are in the line right now (waiting or called). */
    public function waitingCount(int $spaBranchId, string $date): int
    {
        return Queue::where('spa_branch_id', $spaBranchId)
            ->where('appointment_date', $date)
            ->whereIn('queue_status', ['Waiting', 'Called'])
            ->count();
    }

    public function listForBranchDate(array $spaBranchIds, string $date, ?string $status = null)
    {
        return Queue::with('appointment.client', 'appointment.services.serviceVariant.service')
            ->whereIn('spa_branch_id', $spaBranchIds)
            ->where('appointment_date', $date)
            ->when($status, fn ($q, $s) => $q->where('queue_status', $s))
            ->orderBy('created_at')
            ->get();
    }

    public function updateStatus(Queue $queue, string $status, array $extra = []): Queue
    {
        $queue->update(array_merge(['queue_status' => $status], $extra));
        return $queue;
    }
}
