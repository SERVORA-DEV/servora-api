<?php

namespace App\Repository;

use App\Models\Review;
use Illuminate\Support\Collection;

class ReviewRepository
{
    // Published reviews only — a review the owner has hidden doesn't count
    // toward the stars a stranger sees. One grouped query for any number of
    // branches, so a 50-card Explore page stays a single extra query.
    // Returns [spa_branch_id => ['avg' => float, 'count' => int]].
    public function ratingsForBranches(array $branchIds): array
    {
        if (! $branchIds) {
            return [];
        }

        return Review::query()
            ->join('appointments', 'appointments.id', '=', 'reviews.appointment_id')
            ->where('reviews.status', 'Published')
            ->whereIn('appointments.spa_branch_id', $branchIds)
            ->groupBy('appointments.spa_branch_id')
            ->selectRaw('appointments.spa_branch_id as branch_id, avg(reviews.rating) as avg_rating, count(*) as review_count')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->branch_id => [
                'avg' => round((float) $row->avg_rating, 1),
                'count' => (int) $row->review_count,
            ]])
            ->all();
    }

    // Sets rating_avg / rating_count on each branch model for the public
    // resources to read (null / 0 when a branch has no reviews yet).
    public function attachRatings(iterable $branches): void
    {
        $branches = Collection::wrap($branches);
        $ratings = $this->ratingsForBranches($branches->pluck('id')->all());

        foreach ($branches as $branch) {
            $branch->setAttribute('rating_avg', $ratings[$branch->id]['avg'] ?? null);
            $branch->setAttribute('rating_count', $ratings[$branch->id]['count'] ?? 0);
        }
    }

    public function publishedForBranch(int $branchId, int $perPage = 20)
    {
        return Review::query()
            ->select('reviews.*')
            ->join('appointments', 'appointments.id', '=', 'reviews.appointment_id')
            ->where('reviews.status', 'Published')
            ->where('appointments.spa_branch_id', $branchId)
            ->with(['client', 'appointment.services.serviceVariant.service'])
            ->orderByDesc('reviews.created_at')
            ->paginate($perPage);
    }
}
