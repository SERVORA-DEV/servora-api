<?php

namespace App\Models;

use App\Support\History;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// An extra the front desk can put on a service during the visit (hot stones
// on a massage): a name, a price, the time it adds, and the service
// categories — optionally only some services in them — it goes with. One for
// the whole business, the same at every branch. The rules are in
// App\Support\ServiceAddon.
//
// On a visit it becomes an appointment_services line under its main service,
// carrying its own copy of the name, price and minutes (App\Support\History),
// so editing or deleting the add-on later never rewrites a past visit.
class Addon extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'addons';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'created_by',
        'name',
        'description',
        'price',
        'duration_minutes',
        'categories',
        'all_services',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'categories' => 'array',
            'all_services' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    /**
     * Whether add-ons exist yet (the migration has been run): the addons
     * tables and appointment_services.addon_id / parent_appointment_service_id,
     * added together. A visit's add-on line keeps its own name and length, so
     * the history columns have to be there too. Until then there are no
     * add-ons and nothing reads or writes any of it.
     */
    public static function ready(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:addons');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('addons')
            && Schema::hasTable('addon_services')
            && Schema::hasColumn('appointment_services', 'addon_id')
            && Schema::hasColumn('appointment_services', 'parent_appointment_service_id')
            && History::ready();
        $ready ? Cache::forever('schema:addons', true) : Cache::put('schema:addons', false, 60);

        return $ready;
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    // The services it is narrowed to (only read when all_services is false).
    public function services()
    {
        return $this->belongsToMany(Service::class, 'addon_services')->withTimestamps();
    }

    /** Where it is available, in the shape App\Support\ServiceAddon reads. */
    public function rule(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'categories' => (array) ($this->categories ?? []),
            'all_services' => (bool) $this->all_services,
            'service_ids' => $this->all_services ? [] : $this->services->pluck('id')->all(),
        ];
    }
}
