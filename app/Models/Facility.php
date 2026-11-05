<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Facility extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'facilities';

    protected $fillable = [
        'uuid',
        'spa_branch_id',
        'name',
        'description',
        'category',
        // How many clients it holds at once. See clientLimit().
        'max_clients',
        'status',
        'is_available',
        'amenities',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'is_available' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    /**
     * Whether facilities have a client limit yet (the migration has been run).
     * Until then every room holds one, and the setting isn't saved.
     */
    public static function hasClientLimit(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Appointment::hasHomeStart).
        $known = Cache::get('schema:facility_client_limit');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('facilities', 'max_clients');
        $ready ? Cache::forever('schema:facility_client_limit', true) : Cache::put('schema:facility_client_limit', false, 60);

        return $ready;
    }

    /** How many clients this room holds at once: one unless the owner says more. */
    public function clientLimit(): int
    {
        return max(1, (int) ($this->attributes['max_clients'] ?? 1));
    }

    /**
     * The limit of each of these rooms, by id. A room not listed holds one.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public static function clientLimits(array $ids): array
    {
        if (! $ids || ! self::hasClientLimit()) {
            return [];
        }

        return self::withTrashed()->whereIn('id', $ids)->pluck('max_clients', 'id')
            ->map(fn ($limit) => max(1, (int) $limit))->all();
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function therapistAssignments()
    {
        return $this->hasMany(TherapistAssignment::class, 'facility_id');
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'facility_services');
    }
}
