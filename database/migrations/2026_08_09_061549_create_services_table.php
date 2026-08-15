<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A business-level service catalog entry (e.g. "Swedish Massage, 60
    // min") — spa_business_id scoped rather than spa_branch_id, since the
    // same service definition can be offered at multiple branches via
    // branch_services (which is what actually turns it on per-branch and
    // optionally overrides the price).
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')
                ->constrained('spa_businesses')
                ->cascadeOnDelete();

            // Loose reference, no FK constraint — same convention as
            // spa_branches.verified_by, since a service shouldn't vanish (or
            // block user deletion) just because whoever created it is gone.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->unsignedInteger('duration_minutes');

            $table->decimal('default_price', 10, 2);
            $table->decimal('default_commission_percentage', 5, 2)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spa_business_id', 'name', 'duration_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
