<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Turns a business-level package on for a specific branch, with an
    // optional per-branch price override — same shape as branch_services.
    public function up(): void
    {
        Schema::create('branch_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();

            $table->decimal('custom_price', 10, 2)->nullable();

            $table->boolean('is_available')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spa_branch_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_packages');
    }
};
