<?php

use App\Support\AppCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SERVORA covers spa work only: one staff member with one client, in one place, start to complete. Salon
// work (hair, nails, lash and brow, makeup) needs staff juggling several clients at once, which the system
// doesn't run. The lists no longer offer it (config/service_categories.php, App\Support\StaffSpecialty), and
// this takes what businesses already have off their menus:
//
//  - services and system templates in those categories, and the rooms set up for them, are soft-deleted
//    (the same as deleting them by hand; past bookings and bills keep their own copy of the names);
//  - a package holding one of those services goes with it (it is sold whole, at a price that includes it);
//  - one a booking still has waiting or under way is only switched off, so that visit can finish;
//  - Foot Spa stays: it is one-to-one spa work that was filed under Nails. It moves to Body Treatment;
//  - staff lose the salon titles, and anyone left with none becomes a Therapist.
//
// The facilities.category CHECK keeps the old values, so a retired room can be restored as it was.
return new class extends Migration
{
    private const CATEGORIES = ['Hair', 'Nails', 'Lash & Brow', 'Makeup'];

    private const TITLES = [
        'Nail Technician', 'Nail Artist', 'Hair Stylist', 'Hair Colorist', 'Hair Treatment Specialist',
        'Makeup Artist', 'Lash Technician', 'Brow Technician', 'Beautician',
    ];

    // Stamped on everything this touches, so down() can put back exactly those rows.
    private const AT = '2026-11-11 00:00:00';

    private const OPEN_VISIT = ['scheduled', 'checked_in', 'in_service'];

    private const OPEN_LINE = ['Pending', 'In Progress'];

    public function up(): void
    {
        DB::table('services')
            ->where('name', 'Foot Spa')->where('category', 'Nails')->whereNull('deleted_at')
            ->update(['category' => 'Body Treatment', 'updated_at' => self::AT]);

        $services = DB::table('services')->whereIn('category', self::CATEGORIES)->whereNull('deleted_at')->pluck('id');
        $busyServices = DB::table('appointment_services as line')
            ->join('appointments as visit', 'visit.id', '=', 'line.appointment_id')
            ->join('service_variants as variant', 'variant.id', '=', 'line.service_variant_id')
            ->whereIn('variant.service_id', $services)
            ->whereIn('line.status', self::OPEN_LINE)
            ->whereIn('visit.status', self::OPEN_VISIT)
            ->distinct()->pluck('variant.service_id');

        $packages = DB::table('package_services')->whereIn('service_id', $services)->distinct()->pluck('package_id');
        $busyPackages = DB::table('appointment_packages as line')
            ->join('appointments as visit', 'visit.id', '=', 'line.appointment_id')
            ->whereIn('line.package_id', $packages)
            ->whereIn('visit.status', self::OPEN_VISIT)
            ->distinct()->pluck('line.package_id');

        $this->retire('services', $services, $busyServices);
        $this->retire('packages', $packages, $busyPackages);

        DB::table('facilities')->whereIn('category', self::CATEGORIES)->whereNull('deleted_at')
            ->update(['deleted_at' => self::AT, 'updated_at' => self::AT]);

        foreach (DB::table('staff')->whereNotNull('specialties')->get(['id', 'role', 'specialties']) as $person) {
            $had = json_decode($person->specialties, true);
            if (! is_array($had) || ! array_intersect($had, self::TITLES)) {
                continue;
            }
            $left = array_values(array_diff($had, self::TITLES));
            if (! $left && $person->role === 'therapist') {
                $left = ['Therapist'];
            }
            DB::table('staff')->where('id', $person->id)->update(['specialties' => json_encode($left), 'updated_at' => self::AT]);
        }

        $this->refreshCaches();
    }

    // The salon titles are not given back: which of them each person held is not kept.
    public function down(): void
    {
        foreach (['services', 'packages', 'facilities'] as $table) {
            DB::table($table)->where('deleted_at', self::AT)->update(['deleted_at' => null]);
        }
        foreach (['services', 'packages'] as $table) {
            DB::table($table)->where('is_active', false)->where('updated_at', self::AT)->update(['is_active' => true]);
        }
        DB::table('services')
            ->where('name', 'Foot Spa')->where('category', 'Body Treatment')->where('updated_at', self::AT)
            ->update(['category' => 'Nails']);

        $this->refreshCaches();
    }

    private function retire(string $table, $ids, $busy): void
    {
        DB::table($table)->whereIn('id', $ids->diff($busy)->all())->update(['deleted_at' => self::AT, 'updated_at' => self::AT]);
        DB::table($table)->whereIn('id', $busy->all())->update(['is_active' => false, 'updated_at' => self::AT]);
    }

    // These updates skip the model events that normally clear them (App\Support\CacheInvalidation).
    private function refreshCaches(): void
    {
        AppCache::bump('templates');
        foreach (DB::table('spa_businesses')->pluck('id') as $businessId) {
            AppCache::bumpBusiness($businessId);
        }
    }
};
