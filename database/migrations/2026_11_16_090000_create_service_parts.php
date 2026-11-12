<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services done by two or more staff at once (a four-hands massage): the service is split into
 * parts ("Upper body", "Lower body"), one staff member each, and each part has its share of the
 * commission (the shares total 100%). A service with no parts is done by one person, as before.
 *
 * Each assignment row keeps which part it was, the part's name and its share at the time, so
 * history and earnings keep their own copy if the owner later renames or re-splits the parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('name', 60);
            // Its share of the commission, in percent; a service's parts total 100.
            $table->decimal('share_percent', 5, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['service_id', 'sort']);
        });

        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->foreignId('service_part_id')->nullable()->after('facility_id')->constrained('service_parts')->nullOnDelete();
            $table->string('part_name', 60)->nullable()->after('service_part_id');
            // The part's share (percent) when this row was assigned; null for a one-person service.
            $table->decimal('commission_share', 5, 2)->nullable()->after('commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_part_id');
            $table->dropColumn(['part_name', 'commission_share']);
        });
        Schema::dropIfExists('service_parts');
    }
};
