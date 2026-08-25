<?php

namespace App\Repository\Business;

use App\Models\Queue;

class QueueRepository
{
    public function create(array $payload): Queue
    {
        return Queue::create($payload);
    }

    // Daily-reset counter per branch — Q-001, Q-002, ... resets each new
    // appointment_date rather than being a global sequence.
    public function nextQueueNumber(int $spaBranchId, string $date): string
    {
        $highest = Queue::where('spa_branch_id', $spaBranchId)
            ->where('appointment_date', $date)
            ->where('queue_number', 'like', 'Q-%')
            ->pluck('queue_number')
            ->map(fn (string $number) => (int) substr($number, 2))
            ->max();

        return 'Q-' . str_pad((string) (($highest ?? 0) + 1), 3, '0', STR_PAD_LEFT);
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
