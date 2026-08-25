<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Maps to the staff_services table — named StaffQualification (not
// StaffService) to avoid colliding with App\Service\Business\StaffService,
// the business logic class for the staff resource itself (same reasoning as
// PackageServiceItem vs. App\Service\Business\PackageService).
class StaffQualification extends Model
{
    protected $table = 'staff_services';

    protected $fillable = [
        'staff_id',
        'service_id',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
