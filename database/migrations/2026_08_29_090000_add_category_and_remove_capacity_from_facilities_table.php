<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORIES = [
        'Massage', 'Facial', 'Body Treatment', 'Hair', 'Nails', 'Waxing',
        'Lash & Brow', 'Makeup', 'Couples', 'VIP', 'Wellness',
    ];

    // Rooms Management redesign: `type` (Room/Suite/Couples Room/VIP Room)
    // becomes `category` (the 11 service-area categories rooms actually get
    // booked under), `capacity` is dropped entirely, and `status` narrows to
    // just the two manually-settable values — 'Occupied' is now always
    // computed live from therapist_assignments (see FacilityResource), never
    // stored. Enum changes go through PostgresSchema rather than
    // Schema::table()->change(), which can't rebuild a CHECK constraint.
    public function up(): void
    {
        // category: backfill from the old `type` values before locking down
        // to the new enum — the two taxonomies barely overlap.
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('type');
        });

        DB::statement("UPDATE facilities SET category = CASE type
            WHEN 'Couples Room' THEN 'Couples'
            WHEN 'VIP Room' THEN 'VIP'
            WHEN 'Suite' THEN 'Wellness'
            ELSE 'Massage'
        END");

        PostgresSchema::redefineEnum('facilities', 'category', self::CATEGORIES, default: 'Massage');

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn(['type', 'capacity']);
        });

        // status: widen first so existing 'Occupied'/'Under Maintenance' rows
        // can be safely remapped, then narrow to the final two values.
        PostgresSchema::redefineEnum('facilities', 'status', [
            'Available', 'Occupied', 'Under Maintenance', 'Maintenance',
        ], default: 'Available');
        DB::statement("UPDATE facilities SET status = 'Maintenance' WHERE status = 'Under Maintenance'");
        DB::statement("UPDATE facilities SET status = 'Available' WHERE status = 'Occupied'");
        PostgresSchema::redefineEnum('facilities', 'status', ['Available', 'Maintenance'], default: 'Available');
    }

    // Best-effort reverse — the category/type remap is inherently lossy
    // (old type values are gone), so this restores the shape, not the data.
    public function down(): void
    {
        // Widen through the union of both value sets before remapping:
        // rows are sitting on 'Maintenance', which isn't in the target list,
        // and a CHECK constraint is added before any UPDATE could fix them.
        PostgresSchema::redefineEnum('facilities', 'status', [
            'Available', 'Occupied', 'Under Maintenance', 'Maintenance',
        ], default: 'Available');
        DB::statement("UPDATE facilities SET status = 'Under Maintenance' WHERE status = 'Maintenance'");
        PostgresSchema::redefineEnum('facilities', 'status', [
            'Available', 'Occupied', 'Under Maintenance',
        ], default: 'Available');

        Schema::table('facilities', function (Blueprint $table) {
            $table->string('type', 50)->nullable()->after('category');
            $table->unsignedInteger('capacity')->default(1)->after('type');
        });

        DB::statement("UPDATE facilities SET type = CASE category
            WHEN 'Couples' THEN 'Couples Room'
            WHEN 'VIP' THEN 'VIP Room'
            WHEN 'Wellness' THEN 'Suite'
            ELSE 'Room'
        END");

        PostgresSchema::redefineEnum('facilities', 'type', [
            'Room', 'Suite', 'Couples Room', 'VIP Room',
        ], default: 'Room');

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
