<?php

namespace App\Repository\Business;

use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class QueueRepository
{
    public function create(array $payload): Queue
    {
        // Its place in the line starts as the moment it joins (to the microsecond).
        if (Queue::ready()) {
            $payload['queued_at'] ??= now();
        }

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
            ->orderByRaw(Queue::lineKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * The ticket right behind this one in the line: the next one still waiting or
     * called, same branch and day. With "prioritize walk-ins" the line is two lines
     * (walk-ins, then reservations), so it is the next in this one's own kind.
     */
    public function nextBehind(Queue $queue, bool $sameKindOnly): ?Queue
    {
        $key = Queue::lineKey();
        $isWalkIn = $queue->appointment?->appointment_type === 'Walk-in';

        return Queue::where('spa_branch_id', $queue->spa_branch_id)
            ->where('appointment_date', $queue->appointment_date->format('Y-m-d'))
            ->whereIn('queue_status', ['Waiting', 'Called'])
            ->where('id', '!=', $queue->id)
            ->where(fn ($q) => $q->whereRaw("{$key} > ?", [$queue->lineTime()])
                ->orWhere(fn ($tie) => $tie->whereRaw("{$key} = ?", [$queue->lineTime()])->where('id', '>', $queue->id)))
            ->when($sameKindOnly, fn ($q) => $q->whereHas('appointment', fn ($a) => $isWalkIn
                ? $a->where('appointment_type', 'Walk-in')
                : $a->where('appointment_type', '!=', 'Walk-in')))
            ->orderByRaw($key)
            ->orderBy('id')
            ->first();
    }

    /**
     * Everyone behind this ticket in the line, in order: still waiting or called, same branch
     * and day, and with "prioritize walk-ins" only those of its own kind (as nextBehind).
     */
    public function allBehind(Queue $queue, bool $sameKindOnly)
    {
        $key = Queue::lineKey();
        $isWalkIn = $queue->appointment?->appointment_type === 'Walk-in';

        return Queue::with('appointment.client')
            ->where('spa_branch_id', $queue->spa_branch_id)
            ->where('appointment_date', $queue->appointment_date->format('Y-m-d'))
            ->whereIn('queue_status', ['Waiting', 'Called'])
            ->where('id', '!=', $queue->id)
            ->where(fn ($q) => $q->whereRaw("{$key} > ?", [$queue->lineTime()])
                ->orWhere(fn ($tie) => $tie->whereRaw("{$key} = ?", [$queue->lineTime()])->where('id', '>', $queue->id)))
            ->when($sameKindOnly, fn ($q) => $q->whereHas('appointment', fn ($a) => $isWalkIn
                ? $a->where('appointment_type', 'Walk-in')
                : $a->where('appointment_type', '!=', 'Walk-in')))
            ->orderByRaw($key)
            ->orderBy('id')
            ->get();
    }

    /**
     * Puts a ticket in the line right after $target: between it and $after (the one behind
     * $target, if any). Nobody else moves, and the ticket numbers stay where they are.
     */
    public function placeAfter(Queue $queue, Queue $target, ?Queue $after): void
    {
        $from = $target->lineTime();
        if ($after) {
            // Halfway between the two, in microseconds since the epoch ('Uu').
            $gap = (int) $after->lineTime()->format('Uu') - (int) $from->format('Uu');
            $place = $gap > 1 ? $from->copy()->addMicroseconds(intdiv($gap, 2)) : $from->copy()->addMicrosecond();
        } else {
            $place = $from->copy()->addMicrosecond();
        }

        $queue->update(['queued_at' => $place]);
    }

    /** Trades two tickets' places in the line. The ticket numbers stay where they are. */
    public function swapPlaces(Queue $a, Queue $b): void
    {
        $first = $a->lineTime();
        $second = $b->lineTime();
        // Two tickets can share a moment only by a hair; keep them apart so the swap shows.
        if ($first->eq($second)) {
            $second = $second->copy()->addMicrosecond();
        }

        DB::transaction(function () use ($a, $b, $first, $second) {
            $a->update(['queued_at' => $second]);
            $b->update(['queued_at' => $first]);
        });
    }

    public function updateStatus(Queue $queue, string $status, array $extra = []): Queue
    {
        $queue->update(array_merge(['queue_status' => $status], $extra));
        return $queue;
    }
}
