<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Maps to the package_services table — named ...Item (not PackageService) to
// avoid colliding with App\Service\Business\PackageService, the business
// logic class for the packages resource itself.
class PackageServiceItem extends Model
{
    protected $table = 'package_services';

    protected $fillable = [
        'package_id',
        'service_id',
        'quantity',
        'sort_order',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
