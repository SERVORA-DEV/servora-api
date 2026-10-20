<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\PackagePlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Package extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'packages';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'created_by',
        'name',
        'code',
        'description',
        'duration_minutes',
        'default_price',
        'loyalty_points',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    /**
     * Whether packages name services yet (the migration has been run):
     * package_services.service_id / duration_minutes and the package_addons
     * table, added together. Until then a package still pins menu lengths
     * and holds no add-ons.
     */
    public static function byService(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:packages_by_service');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('package_services', 'service_id')
            && Schema::hasColumn('package_services', 'duration_minutes')
            && Schema::hasTable('package_addons');
        $ready ? Cache::forever('schema:packages_by_service', true) : Cache::put('schema:packages_by_service', false, 60);

        return $ready;
    }

    /**
     * Whether package_services.price exists yet (the migration has been run):
     * what each service is worth in the package. Until then nothing writes it
     * and every line is the menu's price, as before.
     */
    public static function hasLinePrice(): bool
    {
        $known = Cache::get('schema:package_service_price');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('package_services', 'price');
        $ready ? Cache::forever('schema:package_service_price', true) : Cache::put('schema:package_service_price', false, 60);

        return $ready;
    }

    /**
     * What to load with a package to read what is in it — each row's service
     * and its menu lengths (the guide), and the add-ons that come with it.
     * $via is the path to the package from the model being loaded
     * ('package.' from a BranchPackage).
     *
     * @return string[]
     */
    public static function contents(string $via = ''): array
    {
        $paths = ['packageServiceItems.serviceVariant.service'];
        if (self::byService()) {
            $paths[] = 'packageServiceItems.service.variants';
            $paths[] = 'packageAddons.addon';
        }

        return array_map(fn ($path) => $via . $path, $paths);
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    // The add-ons that come with it, each on one of its services.
    public function packageAddons()
    {
        return $this->hasMany(PackageAddon::class, 'package_id');
    }

    /** Its add-ons that are still on the menu and switched on (none until they exist). */
    public function includedAddons()
    {
        return self::byService() && $this->relationLoaded('packageAddons')
            ? $this->packageAddons->filter(fn (PackageAddon $row) => $row->offered())->values()
            : collect();
    }

    /**
     * The package's fixed duration: its services' minutes and its add-ons'
     * time (App\Support\PackagePlan). The stored figure when what is in it
     * isn't loaded.
     */
    public function totalMinutes(): int
    {
        if (! $this->relationLoaded('packageServiceItems')) {
            return (int) ($this->duration_minutes ?? 0);
        }

        $total = PackagePlan::totalMinutes(
            $this->packageServiceItems
                ->filter(fn (PackageServiceItem $item) => $item->theService() !== null)
                ->map(fn (PackageServiceItem $item) => ['minutes' => $item->minutes(), 'quantity' => $item->quantity])
                ->all(),
            $this->includedAddons()->map(fn (PackageAddon $row) => $row->addon->duration_minutes)->all(),
        );

        return $total > 0 ? $total : (int) ($this->duration_minutes ?? 0);
    }

    // Ordered by sort_order — PackageServiceItem carries quantity/sort_order,
    // the actual Service comes through its own service() relation.
    public function packageServiceItems()
    {
        return $this->hasMany(PackageServiceItem::class, 'package_id')->orderBy('sort_order');
    }

    public function branchPackages()
    {
        return $this->hasMany(BranchPackage::class, 'package_id');
    }
}
