<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// A client's rating of a completed appointment. Read by the owner's
// Branch Settings → Marketplace → Reviews summary.
class Review extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['uuid', 'appointment_id', 'client_id', 'rating', 'comment', 'is_anonymous', 'status', 'reviewed_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_anonymous' => 'boolean', 'reviewed_at' => 'datetime'];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    // A client rates a spa once. Reviews hang off a visit and are shown per
    // branch (its page, its rating), so "a spa" is the branch: one review per
    // client per branch, from whichever visit they rated it on.
    public static function ratedBranch(int $clientId, int $branchId): bool
    {
        return self::where('client_id', $clientId)
            ->whereHas('appointment', fn ($q) => $q->where('spa_branch_id', $branchId))
            ->exists();
    }

    // The same check as a column on an appointments query (`spa_rated`), so
    // a list of bookings needs no query per booking.
    public static function ratedBranchColumn(): \Illuminate\Database\Query\Builder
    {
        return self::query()->toBase()
            ->selectRaw('1')
            ->join('appointments as rated_visit', 'rated_visit.id', '=', 'reviews.appointment_id')
            ->whereColumn('reviews.client_id', 'appointments.client_id')
            ->whereColumn('rated_visit.spa_branch_id', 'appointments.spa_branch_id')
            ->whereNull('reviews.deleted_at')
            ->limit(1);
    }
}
