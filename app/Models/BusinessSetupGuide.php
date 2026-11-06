<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// One business's setup guide progress (Business\SetupGuideController). A step
// done once is done for the whole business: on any device, branch or login.
class BusinessSetupGuide extends Model
{
    protected $table = 'business_setup_guides';

    protected $fillable = [
        'spa_business_id',
        'done',
        'skipped',
        'finished',
    ];

    protected function casts(): array
    {
        return [
            'done' => 'array',
            'skipped' => 'array',
            'finished' => 'boolean',
        ];
    }

    /**
     * Whether the table exists yet (the migration has been run). "Yes" is
     * remembered for good, "not yet" for a minute (same as StaffTimeOff::ready).
     */
    public static function ready(): bool
    {
        $known = Cache::get('schema:business_setup_guides');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('business_setup_guides');
        $ready ? Cache::forever('schema:business_setup_guides', true) : Cache::put('schema:business_setup_guides', false, 60);

        return $ready;
    }
}
